<?php

namespace App\Console\Commands;

use App\Models\CompDefinition;
use App\Services\Tft\StaticData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Imports comp definitions (name, units, traits, levelling) from a manually
 * exported comps JSON file. Replaces all definitions of that set.
 *
 * Only the definitions are used; statistics come from our own match data.
 */
class ImportComps extends Command
{
    protected $signature = 'tft:import-comps
        {file=storage/comps-import/comps_data1.json : Path to the exported comps JSON, relative to the app root}';

    protected $description = 'Import comp definitions from an exported comps JSON file';

    public function handle(StaticData $static): int
    {
        $path = base_path((string) $this->argument('file'));

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $data = json_decode((string) file_get_contents($path), true);
        $clusters = $data['results']['data']['cluster_details'] ?? null;
        $set = is_string($data['tft_set'] ?? null) && preg_match('/(\d+)/', $data['tft_set'], $m) ? (int) $m[1] : null;

        if (! is_array($clusters) || $set === null) {
            $this->error('Unexpected file format: expected results.data.cluster_details and tft_set.');

            return self::FAILURE;
        }

        if (! $static->isLoaded()) {
            $this->warn('Champion data is missing; run `php artisan tft:import-static` first for proper names.');
        }

        $definitions = [];

        foreach ($clusters as $id => $cluster) {
            $units = $this->list($cluster['units_string'] ?? '');
            $nameParts = is_array($cluster['name'] ?? null) ? $cluster['name'] : [];

            if ($units === [] || $nameParts === []) {
                continue;
            }

            $definitions[] = [
                'set_number' => $set,
                'external_id' => (string) ($cluster['Cluster'] ?? $id),
                'name' => implode(' ', array_map(
                    fn (array $part) => ($part['type'] ?? null) === 'trait'
                        ? $static->trait((string) $part['name'])['name']
                        : $static->champion((string) $part['name'])['name'],
                    $nameParts,
                )),
                'units' => $units,
                'carries' => array_values(array_map(
                    fn (array $part) => (string) $part['name'],
                    array_filter($nameParts, fn (array $part) => ($part['type'] ?? null) === 'unit'),
                )),
                'traits' => array_map(function (string $trait) {
                    // "DA_Juggernaut18_3" -> trait "DA_Juggernaut18" at tier 3.
                    preg_match('/^(.*)_(\d+)$/', $trait, $m);

                    return ['name' => $m[1] ?? $trait, 'tier' => (int) ($m[2] ?? 1)];
                }, $this->list($cluster['traits_string'] ?? '')),
                'levelling' => is_string($cluster['levelling'] ?? null) ? $cluster['levelling'] : null,
            ];
        }

        DB::transaction(function () use ($set, $definitions) {
            CompDefinition::where('set_number', $set)->delete();

            foreach ($definitions as $definition) {
                CompDefinition::create($definition);
            }
        });

        // Comp stats and baselines are cached per set.
        Cache::forget("meta-comps:{$set}");

        $this->info(sprintf('Imported %d comps for Set %d.', count($definitions), $set));

        return self::SUCCESS;
    }

    /**
     * "A, B, C" -> ["A", "B", "C"]
     *
     * @return list<string>
     */
    private function list(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
