<?php

namespace App\Jobs;

use App\Models\Player;
use App\Services\Riot\RiotApiException;
use App\Services\Riot\RiotClient;
use App\Services\Tft\MatchImporter;
use Illuminate\Foundation\Bus\Dispatchable;
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

    public function handle(RiotClient $riot, MatchImporter $importer): void
    {
        $player = $this->player;

        try {
            $player->league = $riot->leagueEntries($player->platform, $player->puuid);

            $matchIds = $riot->matchIds($player->platform, $player->puuid, config('services.riot.match_count'));
            $importer->importMissing($riot, $player->platform, $matchIds);

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
}
