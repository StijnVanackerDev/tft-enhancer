<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Jobs\SyncPlayerMatches;
use App\Models\Participant;
use App\Models\Player;
use App\Services\Riot\RiotApiException;
use App\Services\Riot\RiotClient;
use App\Services\Tft\PlayerStats;
use App\Services\Tft\StaticData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PlayerController extends Controller
{
    public function __construct(
        private readonly RiotClient $riot,
        private readonly StaticData $static,
        private readonly PlayerStats $stats,
    ) {}

    /**
     * Search form: "Name#TAG" + server -> player page.
     */
    public function lookup(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'riot_id' => ['required', 'string', 'max:30', 'regex:/^[^#]{3,16}#[\pL\pN]{2,5}$/u'],
            'platform' => ['required', Rule::enum(Platform::class)],
        ], [
            'riot_id.regex' => 'Enter your Riot ID as Name#TAG.',
        ]);

        [$gameName, $tagLine] = explode('#', trim($validated['riot_id']), 2);

        $player = $this->resolve(Platform::from($validated['platform']), trim($gameName), trim($tagLine));

        if ($player === null) {
            throw ValidationException::withMessages([
                'riot_id' => 'No player found with that Riot ID.',
            ]);
        }

        return to_route('players.show', [$player->platform, $player->slug]);
    }

    public function show(Platform $platform, string $riotId): Response
    {
        $player = $this->resolve(
            $platform,
            Str::beforeLast($riotId, '-'),
            Str::afterLast($riotId, '-'),
        );

        abort_if($player === null, 404);

        if ($player->needsSync() && $player->claimSync()) {
            SyncPlayerMatches::dispatchAfterResponse($player);
        }

        /** @var Collection<int, Participant> $games */
        $games = $player->participations()
            ->with('match')
            ->join('tft_matches', 'tft_matches.id', '=', 'participants.tft_match_id')
            ->orderByDesc('tft_matches.played_at')
            ->select('participants.*')
            ->limit(config('services.riot.match_count'))
            ->get();

        // Unit/trait stats only make sense within one set.
        $latestSet = $games->max(fn (Participant $p) => $p->match->set_number);
        $setGames = $games->filter(fn (Participant $p) => $p->match->set_number === $latestSet);

        return Inertia::render('players/Show', [
            'player' => $this->presentPlayer($player),
            'summary' => $this->stats->summary($games),
            'set' => $latestSet,
            'units' => $this->stats->units($setGames),
            'traits' => $this->stats->traits($setGames),
            'matches' => $games->map(fn (Participant $p) => $this->presentGame($p))->values(),
        ]);
    }

    public function refresh(Player $player): RedirectResponse
    {
        if ($player->needsSync() && $player->claimSync()) {
            SyncPlayerMatches::dispatchAfterResponse($player);
        }

        return back();
    }

    /**
     * Find a player we already know, or look them up through the Riot API.
     */
    private function resolve(Platform $platform, string $gameName, string $tagLine): ?Player
    {
        $player = Player::query()
            ->where('platform', $platform)
            ->whereRaw('lower(game_name) = ?', [Str::lower($gameName)])
            ->whereRaw('lower(tag_line) = ?', [Str::lower($tagLine)])
            ->first();

        if ($player !== null) {
            return $player;
        }

        try {
            $account = $this->riot->accountByRiotId($platform, $gameName, $tagLine);
        } catch (RiotApiException $e) {
            report($e);

            throw ValidationException::withMessages([
                'riot_id' => 'Could not reach the Riot API right now. Please try again later.',
            ]);
        }

        if ($account === null) {
            return null;
        }

        return Player::updateOrCreate(['puuid' => $account['puuid']], [
            'platform' => $platform,
            'game_name' => $account['gameName'],
            'tag_line' => $account['tagLine'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPlayer(Player $player): array
    {
        $ranked = collect($player->league ?? [])->firstWhere('queueType', 'RANKED_TFT');

        return [
            'id' => $player->id,
            'gameName' => $player->game_name,
            'tagLine' => $player->tag_line,
            'platform' => $player->platform->value,
            'platformLabel' => $player->platform->label(),
            'slug' => $player->slug,
            'rank' => $ranked ? [
                'tier' => $ranked['tier'] ?? null,
                'division' => $ranked['rank'] ?? null,
                'lp' => $ranked['leaguePoints'] ?? 0,
                'wins' => $ranked['wins'] ?? 0,
                'losses' => $ranked['losses'] ?? 0,
            ] : null,
            'syncedAt' => $player->synced_at?->toIso8601String(),
            'isSyncing' => $player->isSyncing(),
            'syncError' => $player->sync_error,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentGame(Participant $p): array
    {
        $traits = collect($p->activeTraits())
            ->sortByDesc(fn (array $t) => [$t['style'], $t['num_units']])
            ->map(fn (array $t) => [
                'id' => $t['name'],
                ...$this->static->trait($t['name']),
                'units' => $t['num_units'],
                'style' => $t['style'],
            ])
            ->values();

        $units = collect($p->units)
            ->map(fn (array $u) => [
                'id' => $u['character_id'],
                ...$this->static->champion($u['character_id']),
                'stars' => $u['tier'],
                'items' => array_map(
                    fn (string $item) => ['id' => $item, ...$this->static->item($item)],
                    $u['items'],
                ),
            ])
            ->sortByDesc(fn (array $u) => [$u['cost'], $u['stars']])
            ->values();

        return [
            'id' => $p->match->match_id,
            'playedAt' => $p->match->played_at->toIso8601String(),
            'duration' => $p->match->game_length,
            'queue' => $p->match->queueName(),
            'patch' => $p->match->patch(),
            'placement' => $p->placement,
            'level' => $p->level,
            'lastRound' => $p->last_round,
            'damage' => $p->damage_to_players,
            'traits' => $traits,
            'units' => $units,
        ];
    }
}
