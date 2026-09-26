<?php

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Models\TftMatch;
use App\Services\Riot\RiotClient;
use App\Services\Tft\MatchImporter;
use App\Services\Tft\MetaComps;
use Illuminate\Console\Command;

/**
 * Fills the database with recent ranked matches, which the comps page and
 * lobby predictions use as "the meta". Crawl several tiers so the data isn't
 * only Challenger. Sleeps through rate limits, so it can run for a while with
 * a development key.
 *
 *   php artisan tft:crawl-meta --tier=challenger --tier=diamond --tier=emerald --players=20
 */
class CrawlMeta extends Command
{
    private const APEX_TIERS = ['challenger', 'grandmaster', 'master'];

    private const DIVISION_TIERS = ['diamond', 'emerald', 'platinum', 'gold', 'silver', 'bronze', 'iron'];

    protected $signature = 'tft:crawl-meta
        {--platform=euw1 : Server to crawl}
        {--tier=* : Tiers to crawl (challenger, grandmaster, master, diamond, emerald, ...); default challenger}
        {--players=10 : Players per tier}
        {--matches=10 : Recent matches per player}';

    protected $description = 'Import recent ranked matches of one or more tiers for comp statistics';

    public function handle(RiotClient $riot, MatchImporter $importer): int
    {
        $platform = Platform::tryFrom((string) $this->option('platform'));
        $tiers = array_map(fn ($tier) => strtolower((string) $tier), (array) $this->option('tier')) ?: ['challenger'];
        $unknown = array_diff($tiers, [...self::APEX_TIERS, ...self::DIVISION_TIERS]);

        if ($platform === null || $unknown !== []) {
            $this->error($platform === null ? 'Unknown platform.' : 'Unknown tier: '.implode(', ', $unknown));

            return self::FAILURE;
        }

        $riot = $riot->waitingOnRateLimits(
            fn (float $seconds) => $this->components->warn(sprintf('Rate limit reached, waiting %d seconds...', ceil($seconds))),
        );

        foreach ($tiers as $tier) {
            $this->crawlTier($riot, $importer, $platform, $tier);
        }

        // Comp stats and baselines are cached per set; make them pick up the new games.
        MetaComps::forgetCache((int) TftMatch::max('set_number'));

        $this->info('Done.');

        return self::SUCCESS;
    }

    private function crawlTier(RiotClient $riot, MatchImporter $importer, Platform $platform, string $tier): void
    {
        $players = (int) $this->option('players');
        $puuids = $this->players($riot, $platform, $tier, $players);

        $this->info(sprintf('%s: collecting match ids of %d players...', ucfirst($tier), count($puuids)));

        $matchIds = [];
        foreach ($puuids as $puuid) {
            array_push($matchIds, ...$riot->matchIds($platform, $puuid, (int) $this->option('matches')));
        }
        $matchIds = array_values(array_unique($matchIds));

        $missing = $importer->missing($matchIds);
        $this->info(sprintf('%s: %d matches found, %d new.', ucfirst($tier), count($matchIds), count($missing)));

        $bar = $this->output->createProgressBar(count($missing));
        // Tag each match right away, so an interrupted crawl keeps its tiers.
        $importer->importMissing($riot, $platform, $missing, function (string $matchId) use ($bar, $importer, $tier) {
            $importer->tagTier([$matchId], $tier);
            $bar->advance();
        });
        $bar->finish();
        $this->newLine();

        $importer->tagTier($matchIds, $tier);
    }

    /**
     * Players to sample: the top of an apex tier, or a random spread over
     * the four divisions of a lower tier.
     *
     * @return list<string>
     */
    private function players(RiotClient $riot, Platform $platform, string $tier, int $count): array
    {
        if (in_array($tier, self::APEX_TIERS, true)) {
            return array_values(collect($riot->apexLeague($platform, $tier)['entries'] ?? [])
                ->sortByDesc('leaguePoints')
                ->take($count)
                ->pluck('puuid')
                ->filter()
                ->map(fn ($p) => (string) $p)
                ->all());
        }

        $perDivision = (int) ceil($count / 4);
        $puuids = [];

        foreach (['I', 'II', 'III', 'IV'] as $division) {
            $entries = collect($riot->tierEntries($platform, $tier, $division))
                // Skip players who stopped playing: their matches are old.
                ->reject(fn (array $e) => $e['inactive'] ?? false)
                ->pluck('puuid')
                ->filter()
                ->shuffle()
                ->take($perDivision);

            array_push($puuids, ...$entries->map(fn ($p) => (string) $p)->all());
        }

        return array_slice($puuids, 0, $count);
    }
}
