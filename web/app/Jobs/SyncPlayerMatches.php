<?php

namespace App\Jobs;

use App\Models\Player;
use App\Models\TftMatch;
use App\Services\Riot\RiotApiException;
use App\Services\Riot\RiotClient;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls a player's rank and recent matches from the Riot API.
 *
 * Dispatched with dispatchAfterResponse(), so it runs right after the page
 * has been sent and no separate queue worker is needed (free hosting).
 * The page polls until Player::isSyncing() is false.
 */
class SyncPlayerMatches
{
    use Dispatchable;

    public function __construct(public Player $player) {}

    public function handle(RiotClient $riot): void
    {
        $player = $this->player;

        try {
            $player->league = $riot->leagueEntries($player->platform, $player->puuid);

            $matchIds = $riot->matchIds($player->platform, $player->puuid, config('services.riot.match_count'));
            $known = TftMatch::query()->whereIn('match_id', $matchIds)->pluck('match_id')->all();

            foreach (array_diff($matchIds, $known) as $matchId) {
                $match = $riot->match($player->platform, $matchId);

                if ($match !== null) {
                    $this->store($player, $match);
                }
            }

            $player->synced_at = now();
            $player->sync_error = null;
        } catch (Throwable $e) {
            Log::warning("Syncing {$player->riot_id} failed: {$e->getMessage()}");
            $player->sync_error = $e instanceof RiotApiException
                ? $e->getMessage()
                : 'Something went wrong while fetching matches.';
        } finally {
            $player->sync_started_at = null;
            $player->save();
        }
    }

    /**
     * @param  array{metadata: array<string, mixed>, info: array<string, mixed>}  $data
     */
    private function store(Player $player, array $data): void
    {
        $info = $data['info'];

        DB::transaction(function () use ($player, $data, $info) {
            $match = TftMatch::create([
                'match_id' => $data['metadata']['match_id'],
                'platform' => $player->platform,
                'played_at' => Carbon::createFromTimestampMs($info['game_datetime']),
                'game_length' => (int) round($info['game_length'] ?? 0),
                'game_version' => $info['game_version'] ?? '',
                'queue_id' => $info['queue_id'] ?? $info['queueId'] ?? null,
                'set_number' => $info['tft_set_number'] ?? null,
                'game_type' => $info['tft_game_type'] ?? null,
            ]);

            foreach ($info['participants'] as $p) {
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
        });
    }
}
