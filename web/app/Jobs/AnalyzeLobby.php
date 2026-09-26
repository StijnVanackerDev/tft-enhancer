<?php

namespace App\Jobs;

use App\Models\LobbyAnalysis;
use App\Models\Participant;
use App\Models\Player;
use App\Models\TftMatch;
use App\Services\Riot\RiotClient;
use App\Services\Tft\LobbyPredictor;
use App\Services\Tft\MatchImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Loads rank and recent matches for everyone in a lobby, then predicts which
 * comps and champions they'll go for.
 *
 * This can take well over 100 Riot API requests, so it runs on the queue and
 * sleeps through rate limits instead of failing. Progress is written to the
 * LobbyAnalysis row, which the page polls.
 */
class AnalyzeLobby implements ShouldQueue
{
    use Queueable;

    /** Rate-limit waits can add up to several minutes. */
    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public LobbyAnalysis $analysis) {}

    public function handle(RiotClient $riot, MatchImporter $importer, LobbyPredictor $predictor): void
    {
        $analysis = $this->analysis;
        $platform = $analysis->platform;
        $perPlayer = config('services.riot.lobby_history');

        $riot = $riot->waitingOnRateLimits(function (float $seconds) use ($analysis) {
            $analysis->status = 'waiting';
            $analysis->waiting_until = now()->addMilliseconds((int) ceil($seconds * 1000));
            $analysis->message = 'Waiting for the Riot API rate limit';
            $analysis->save();
        });

        // Manually entered lobbies only have Riot IDs: look those up first.
        [$players, $notFound] = $this->resolveRiotIds($riot, $analysis);

        // First estimate: rank + match list per player, and up to a full
        // history each. Corrected once we know which matches we already have.
        $analysis->update([
            'status' => 'running',
            'progress_done' => 0,
            'progress_total' => count($players) * (2 + $perPlayer),
            'message' => 'Looking up players',
        ]);

        $endTime = $analysis->history_before?->getTimestamp();
        $histories = [];
        $leagues = [];

        foreach ($players as $member) {
            $name = $member['gameName'] ?? 'player';

            $leagues[$member['puuid']] = $riot->leagueEntries($platform, $member['puuid']);
            $analysis->advance("Loaded rank of {$name}");

            $histories[$member['puuid']] = $riot->matchIds($platform, $member['puuid'], $perPlayer, $endTime);
            $analysis->advance("Loaded match list of {$name}");

            $this->rememberPlayer($member, $analysis, $leagues[$member['puuid']]);
        }

        $missing = $importer->missing(array_merge(...array_values($histories)));
        $analysis->update(['progress_total' => $analysis->progress_done + count($missing)]);

        $loaded = 0;
        $importer->importMissing($riot, $platform, $missing, function () use ($analysis, &$loaded, $missing) {
            $loaded++;
            $analysis->advance(sprintf('Loaded match %d of %d', $loaded, count($missing)));
        });

        foreach ($histories as $puuid => $matchIds) {
            $importer->tagTier($matchIds, MatchImporter::rankedTier($leagues[$puuid]));
        }

        $analysis->message = 'Predicting comps';
        $analysis->save();

        $lobby = array_map(fn (array $member) => [
            'puuid' => $member['puuid'],
            'gameName' => $member['gameName'] ?? null,
            'tagLine' => $member['tagLine'] ?? null,
            'league' => $leagues[$member['puuid']],
            'games' => $this->history($member['puuid'], $histories[$member['puuid']], $analysis->set_number),
            'actual' => $this->actualResult($analysis, $member['puuid']),
        ], $players);

        $analysis->update([
            'status' => 'done',
            'message' => null,
            'waiting_until' => null,
            'progress_done' => $analysis->progress_total,
            'result' => [
                ...$predictor->predict($lobby, $analysis->set_number, $analysis->player->puuid),
                // Entered Riot IDs that don't exist on this server.
                'notFound' => $notFound,
            ],
        ]);
    }

    /**
     * Looks up the puuid of every participant that only has a Riot ID, and
     * stores the resolved lobby. Unknown Riot IDs are left out.
     *
     * @return array{list<array{puuid: string, gameName: ?string, tagLine: ?string}>, list<string>}
     */
    private function resolveRiotIds(RiotClient $riot, LobbyAnalysis $analysis): array
    {
        $unresolved = array_filter($analysis->participants, fn (array $m) => $m['puuid'] === null);

        if ($unresolved !== []) {
            $analysis->update([
                'status' => 'running',
                'progress_done' => 0,
                'progress_total' => count($unresolved),
                'message' => 'Looking up Riot IDs',
            ]);
        }

        $players = [];
        $notFound = [];

        foreach ($analysis->participants as $member) {
            if ($member['puuid'] !== null) {
                $players[$member['puuid']] = $member;

                continue;
            }

            $riotId = "{$member['gameName']}#{$member['tagLine']}";
            $account = $riot->accountByRiotId($analysis->platform, (string) $member['gameName'], (string) $member['tagLine']);
            $analysis->advance("Looked up {$riotId}");

            if ($account === null) {
                $notFound[] = $riotId;

                continue;
            }

            $players[$account['puuid']] = [
                'puuid' => $account['puuid'],
                'gameName' => $account['gameName'],
                'tagLine' => $account['tagLine'],
            ];
        }

        if ($unresolved !== []) {
            $analysis->update(['participants' => array_values($players)]);
        }

        return [array_values($players), $notFound];
    }

    public function failed(?Throwable $e): void
    {
        Log::warning('Lobby analysis failed: '.$e?->getMessage());

        $this->analysis->update([
            'status' => 'failed',
            'waiting_until' => null,
            'message' => 'The analysis failed: '.($e?->getMessage() ?? 'unknown error'),
        ]);
    }

    /**
     * A player's stored games from the given match list and set, newest first.
     *
     * @param  list<string>  $matchIds
     * @return Collection<int, Participant>
     */
    private function history(string $puuid, array $matchIds, ?int $set)
    {
        return Participant::query()
            ->where('puuid', $puuid)
            ->whereHas('match', fn ($q) => $q
                ->againstPlayers()
                ->whereIn('match_id', $matchIds)
                // Never let the analysed match itself leak into the history.
                ->where('match_id', '!=', $this->analysis->source_id)
                ->when($set !== null, fn ($q) => $q->where('set_number', $set)))
            ->join('tft_matches', 'tft_matches.id', '=', 'participants.tft_match_id')
            ->orderByDesc('tft_matches.played_at')
            ->select('participants.*')
            ->get();
    }

    /**
     * For a finished match: what this player actually played in it.
     */
    private function actualResult(LobbyAnalysis $analysis, string $puuid): ?Participant
    {
        if ($analysis->source !== LobbyAnalysis::SOURCE_MATCH) {
            return null;
        }

        return TftMatch::where('match_id', $analysis->source_id)->first()
            ?->participants()
            ->where('puuid', $puuid)
            ->first();
    }

    /**
     * Keep lobby players as normal players too, so their pages open instantly.
     *
     * @param  array{puuid: string, gameName: ?string, tagLine: ?string}  $member
     * @param  list<array<string, mixed>>  $league
     */
    private function rememberPlayer(array $member, LobbyAnalysis $analysis, array $league): void
    {
        if (blank($member['gameName'] ?? null) || blank($member['tagLine'] ?? null)) {
            return;
        }

        Player::updateOrCreate(['puuid' => $member['puuid']], [
            'platform' => $analysis->platform,
            'game_name' => $member['gameName'],
            'tag_line' => $member['tagLine'],
            'league' => $league,
        ]);
    }
}
