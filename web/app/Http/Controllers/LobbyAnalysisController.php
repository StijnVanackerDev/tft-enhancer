<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeLobby;
use App\Models\LobbyAnalysis;
use App\Models\Player;
use App\Models\TftMatch;
use App\Services\Riot\LeagueClient;
use App\Services\Riot\RiotApiException;
use App\Services\Riot\RiotClient;
use App\Services\Tft\MetaComps;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lobby analysis is behind the `features.lobby_analysis` flag: Riot doesn't
 * allow lobby/player aggregate stats during gameplay without approval.
 */
class LobbyAnalysisController extends Controller
{
    public function store(Request $request, Player $player, RiotClient $riot, MetaComps $meta): RedirectResponse
    {
        abort_unless(config('features.lobby_analysis'), 404);

        $validated = $request->validate([
            'source' => ['required', Rule::in([LobbyAnalysis::SOURCE_MATCH, LobbyAnalysis::SOURCE_LIVE, LobbyAnalysis::SOURCE_MANUAL, LobbyAnalysis::SOURCE_CLIENT])],
            'match_id' => ['required_if:source,match', 'nullable', 'string'],
            'names' => ['required_if:source,manual', 'nullable', 'string', 'max:1000'],
        ]);

        $attributes = match ($validated['source']) {
            LobbyAnalysis::SOURCE_MATCH => $this->fromMatch($player, (string) $validated['match_id']),
            LobbyAnalysis::SOURCE_MANUAL => $this->fromNames($player, (string) $validated['names'], $meta),
            LobbyAnalysis::SOURCE_CLIENT => $this->fromClient($player, $meta),
            default => $this->fromLiveGame($player, $riot, $meta),
        };

        // A finished match never changes, so its analysis is reused forever;
        // a live or manually entered lobby only for an hour.
        $existing = LobbyAnalysis::query()
            ->where('source', $attributes['source'])
            ->where('source_id', $attributes['source_id'])
            ->where('status', '!=', 'failed')
            ->when(
                $attributes['source'] !== LobbyAnalysis::SOURCE_MATCH,
                fn ($q) => $q->where('created_at', '>', now()->subHour()),
            )
            ->latest()
            ->first();

        if ($existing !== null) {
            return to_route('lobby.show', $existing);
        }

        $analysis = LobbyAnalysis::create([...$attributes, 'player_id' => $player->id, 'platform' => $player->platform]);
        AnalyzeLobby::dispatch($analysis);

        return to_route('lobby.show', $analysis);
    }

    public function show(LobbyAnalysis $analysis): Response
    {
        abort_unless(config('features.lobby_analysis'), 404);

        return Inertia::render('lobby/Show', [
            'analysis' => [
                'id' => $analysis->id,
                'source' => $analysis->source,
                'sourceId' => $analysis->source_id,
                'status' => $analysis->status,
                'progressDone' => $analysis->progress_done,
                'progressTotal' => $analysis->progress_total,
                'waitingUntil' => $analysis->waiting_until?->toIso8601String(),
                'message' => $analysis->message,
                'createdAt' => $analysis->created_at?->toIso8601String(),
                'player' => [
                    'gameName' => $analysis->player->game_name,
                    'tagLine' => $analysis->player->tag_line,
                    'platform' => $analysis->player->platform->value,
                    'slug' => $analysis->player->slug,
                ],
                'result' => $analysis->result,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fromMatch(Player $player, string $matchId): array
    {
        $match = TftMatch::where('match_id', $matchId)->with('participants')->first();

        if ($match === null || ! $match->participants->contains('puuid', $player->puuid)) {
            throw ValidationException::withMessages(['match_id' => 'Unknown match for this player.']);
        }

        return [
            'source' => LobbyAnalysis::SOURCE_MATCH,
            'source_id' => $match->match_id,
            'history_before' => $match->played_at,
            'set_number' => $match->set_number,
            'participants' => $match->participants
                ->map(fn ($p) => ['puuid' => $p->puuid, 'gameName' => $p->game_name, 'tagLine' => $p->tag_line])
                ->values()
                ->all(),
        ];
    }

    /**
     * The game in progress, read from the Riot client on this PC. The page's
     * player must be the one logged in to that client.
     *
     * @return array<string, mixed>
     */
    private function fromClient(Player $player, MetaComps $meta): array
    {
        try {
            $game = app(LeagueClient::class)->currentGame();
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['source' => $e->getMessage()]);
        }

        if (! collect($game['players'])->contains('puuid', $player->puuid)) {
            $me = trim(($game['self']['gameName'] ?? '').'#'.($game['self']['tagLine'] ?? ''), '#');

            throw ValidationException::withMessages([
                'source' => "{$player->riot_id} isn't in the game running on this PC".($me !== '' ? " (logged in: {$me}). Open your own player page." : '.'),
            ]);
        }

        $others = array_values(array_filter($game['players'], fn (array $p) => $p['puuid'] !== $player->puuid));

        return [
            'source' => LobbyAnalysis::SOURCE_CLIENT,
            'source_id' => $game['gameId'],
            'history_before' => null,
            'set_number' => $meta->latestSet(),
            'participants' => [
                ['puuid' => $player->puuid, 'gameName' => $player->game_name, 'tagLine' => $player->tag_line],
                ...$others,
            ],
        ];
    }

    /**
     * A lobby typed in by hand: the searched player plus up to 7 Riot IDs
     * ("Name#TAG", one per line or separated by commas/semicolons).
     *
     * @return array<string, mixed>
     */
    private function fromNames(Player $player, string $names, MetaComps $meta): array
    {
        $opponents = [];
        $invalid = [];

        foreach (preg_split('/[\r\n,;]+/', $names) ?: [] as $line) {
            $riotId = trim($line);

            if ($riotId === '') {
                continue;
            }

            if (! preg_match('/^([^#]{3,16})#([\pL\pN]{2,5})$/u', $riotId, $m)) {
                $invalid[] = $riotId;

                continue;
            }

            $gameName = trim($m[1]);
            $tagLine = trim($m[2]);
            $key = Str::lower("{$gameName}#{$tagLine}");

            // The searched player is always part of the lobby already.
            if ($key !== Str::lower($player->riot_id)) {
                $opponents[$key] = ['puuid' => null, 'gameName' => $gameName, 'tagLine' => $tagLine];
            }
        }

        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'names' => 'Not a valid Riot ID (use Name#TAG): '.implode(', ', array_slice($invalid, 0, 3)),
            ]);
        }

        if ($opponents === [] || count($opponents) > LobbyAnalysis::MAX_MANUAL_OPPONENTS) {
            throw ValidationException::withMessages([
                'names' => sprintf('Enter between 1 and %d other players.', LobbyAnalysis::MAX_MANUAL_OPPONENTS),
            ]);
        }

        ksort($opponents);

        return [
            'source' => LobbyAnalysis::SOURCE_MANUAL,
            // Same names in any order = same lobby.
            'source_id' => substr(sha1($player->puuid.'|'.implode('|', array_keys($opponents))), 0, 40),
            'history_before' => null,
            'set_number' => $meta->latestSet(),
            'participants' => [
                ['puuid' => $player->puuid, 'gameName' => $player->game_name, 'tagLine' => $player->tag_line],
                ...array_values($opponents),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fromLiveGame(Player $player, RiotClient $riot, MetaComps $meta): array
    {
        try {
            $game = $riot->activeGame($player->platform, $player->puuid);
        } catch (RiotApiException $e) {
            throw ValidationException::withMessages([
                'source' => $e->getCode() === 403
                    ? 'Riot does not allow live game lookups with the current API key.'
                    : $e->getMessage(),
            ]);
        }

        if ($game === null) {
            throw ValidationException::withMessages(['source' => 'This player is not in a game right now.']);
        }

        /** @var list<array<string, mixed>> $participants */
        $participants = $game['participants'] ?? [];

        return [
            'source' => LobbyAnalysis::SOURCE_LIVE,
            'source_id' => (string) ($game['gameId'] ?? Str::uuid()),
            'history_before' => null,
            'set_number' => $meta->latestSet(),
            'participants' => array_map(function (array $p) {
                $riotId = (string) ($p['riotId'] ?? '');

                return [
                    'puuid' => (string) $p['puuid'],
                    'gameName' => $riotId !== '' ? Str::beforeLast($riotId, '#') : null,
                    'tagLine' => str_contains($riotId, '#') ? Str::afterLast($riotId, '#') : null,
                ];
            }, $participants),
        ];
    }
}
