<?php

namespace App\Services\Tft;

use App\Enums\Platform;
use App\Models\TftMatch;
use App\Services\Riot\RiotClient;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stores Riot match data (the match plus all 8 participants).
 */
class MatchImporter
{
    /**
     * Fetch and store every match id we don't have yet.
     *
     * @param  list<string>  $matchIds
     * @param  (Closure(string): void)|null  $afterEach  Called with the match id after each fetch.
     */
    public function importMissing(RiotClient $riot, Platform $platform, array $matchIds, ?Closure $afterEach = null): int
    {
        $imported = 0;

        foreach ($this->missing($matchIds) as $matchId) {
            $data = $riot->match($platform, $matchId);

            try {
                if ($data !== null && $this->import($platform, $data) !== null) {
                    $imported++;
                }
            } catch (QueryException $e) {
                // One malformed match shouldn't stop a whole sync or analysis.
                Log::warning("Skipped match {$matchId}: {$e->getMessage()}");
            }

            if ($afterEach !== null) {
                $afterEach($matchId);
            }
        }

        return $imported;
    }

    /**
     * @param  list<string>  $matchIds
     * @return list<string>
     */
    public function missing(array $matchIds): array
    {
        $known = TftMatch::query()->whereIn('match_id', $matchIds)->pluck('match_id')->all();

        return array_values(array_diff(array_unique($matchIds), $known));
    }

    /**
     * @param  array{metadata: array<string, mixed>, info: array<string, mixed>}  $data
     */
    public function import(Platform $platform, array $data): ?TftMatch
    {
        $info = $data['info'];

        if (TftMatch::where('match_id', $data['metadata']['match_id'])->exists()) {
            return null;
        }

        // Practice games: every bot has the puuid "BOT". Only the humans are
        // stored, and the match is marked so it stays out of all statistics.
        $humans = array_values(array_filter($info['participants'], fn (array $p) => $p['puuid'] !== TftMatch::BOT_PUUID));
        $hasBots = count($humans) < count($info['participants']);

        return DB::transaction(function () use ($platform, $data, $info, $humans, $hasBots) {
            $match = TftMatch::create([
                'match_id' => $data['metadata']['match_id'],
                'platform' => $platform,
                'played_at' => Carbon::createFromTimestampMs($info['game_datetime']),
                'game_length' => (int) round($info['game_length'] ?? 0),
                'game_version' => $info['game_version'] ?? '',
                'queue_id' => $info['queue_id'] ?? $info['queueId'] ?? null,
                'set_number' => $info['tft_set_number'] ?? null,
                'game_type' => $hasBots ? TftMatch::GAME_TYPE_BOTS : ($info['tft_game_type'] ?? null),
            ]);

            foreach ($humans as $p) {
                $match->participants()->create([
                    'puuid' => $p['puuid'],
                    'game_name' => $p['riotIdGameName'] ?? null,
                    'tag_line' => $p['riotIdTagline'] ?? null,
                    'placement' => $p['placement'],
                    'level' => $p['level'] ?? 0,
                    'gold_left' => $p['gold_left'] ?? 0,
                    'last_round' => $p['last_round'] ?? 0,
                    'damage_to_players' => $p['total_damage_to_players'] ?? 0,
                    'players_eliminated' => $p['players_eliminated'] ?? 0,
                    // Augments are deliberately not stored.
                    'traits' => array_map(fn (array $t) => [
                        'name' => $t['name'],
                        'num_units' => $t['num_units'] ?? 0,
                        'style' => $t['style'] ?? 0,
                        'tier_current' => $t['tier_current'] ?? 0,
                        'tier_total' => $t['tier_total'] ?? 0,
                    ], $p['traits'] ?? []),
                    'units' => array_map(fn (array $u) => [
                        'character_id' => $u['character_id'],
                        'tier' => $u['tier'] ?? 1,
                        'rarity' => $u['rarity'] ?? 0,
                        'items' => $u['itemNames'] ?? [],
                    ], $p['units'] ?? []),
                ]);
            }

            return $match;
        });
    }
}
