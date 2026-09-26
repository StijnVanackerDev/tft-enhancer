<?php

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Models\TftMatch;
use App\Services\Riot\RiotClient;
use App\Services\Tft\MatchImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Fills the database with recent high-elo matches, which the comps page and
 * lobby predictions use as "the meta". Sleeps through rate limits, so it can
 * run for a while with a development key.
 */
class CrawlMeta extends Command
{
    protected $signature = 'tft:crawl-meta
        {--platform=euw1 : Server to crawl}
        {--players=10 : Number of top Challenger players}
        {--matches=10 : Recent matches per player}';

    protected $description = 'Import recent Challenger matches for comp statistics';

    public function handle(RiotClient $riot, MatchImporter $importer): int
    {
        $platform = Platform::tryFrom((string) $this->option('platform'));

        if ($platform === null) {
            $this->error('Unknown platform.');

            return self::FAILURE;
        }

        $riot = $riot->waitingOnRateLimits(
            fn (float $seconds) => $this->components->warn(sprintf('Rate limit reached, waiting %d seconds...', ceil($seconds))),
        );

        $entries = collect($riot->challengerLeague($platform)['entries'] ?? [])
            ->sortByDesc('leaguePoints')
            ->take((int) $this->option('players'));

        $this->info("Collecting match ids of {$entries->count()} players...");

        $matchIds = [];
        foreach ($entries as $entry) {
            array_push($matchIds, ...$riot->matchIds($platform, (string) $entry['puuid'], (int) $this->option('matches')));
        }
        $matchIds = array_values(array_unique($matchIds));

        $missing = $importer->missing($matchIds);
        $this->info(sprintf('%d matches found, %d new.', count($matchIds), count($missing)));

        $bar = $this->output->createProgressBar(count($missing));
        $importer->importMissing($riot, $platform, $missing, fn () => $bar->advance());
        $bar->finish();
        $this->newLine();

        // Comp stats are cached; make them pick up the new games.
        Cache::forget('meta-comps:'.TftMatch::max('set_number'));

        $this->info('Done.');

        return self::SUCCESS;
    }
}
