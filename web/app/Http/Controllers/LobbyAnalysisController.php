<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeLobby;
use App\Models\LobbyAnalysis;
use App\Models\Player;
use App\Models\TftMatch;
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
            'source' => ['required', Rule::in([LobbyAnalysis::SOURCE_MATCH, LobbyAnalysis::SOURCE_LIVE])],
            'match_id' => ['required_if:source,match', 'nullable', 'string'],
        ]);

        $attributes = $validated['source'] === LobbyAnalysis::SOURCE_MATCH
            ? $this->fromMatch($player, (string) $validated['match_id'])
            : $this->fromLiveGame($player, $riot, $meta);

        $existing = LobbyAnalysis::query()
            ->where('source', $attributes['source'])
            ->where('source_id', $attributes['source_id'])
            ->where('status', '!=', 'failed')
            ->where('created_at', '>', now()->subHour())
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
