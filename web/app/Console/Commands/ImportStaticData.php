<?php

namespace App\Console\Commands;

use App\Services\Tft\StaticData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ImportStaticData extends Command
{
    protected $signature = 'tft:import-static';

    protected $description = 'Download champion, trait and item names/icons from Community Dragon';

    private const SOURCE = 'https://raw.communitydragon.org/latest/cdragon/tft/en_us.json';

    public function handle(): int
    {
        $this->info('Downloading '.self::SOURCE.' (~25 MB)...');

        $response = Http::timeout(180)->get(self::SOURCE);

        if ($response->failed()) {
            $this->error("Download failed with HTTP {$response->status()}.");

            return self::FAILURE;
        }

        /** @var array{setData: list<array<string, mixed>>, items: list<array<string, mixed>>} $data */
        $data = $response->json();

        // Older sets first, so the newest version of a name/icon wins.
        $sets = collect($data['setData'])->sortBy('number');

        $champions = [];
        $traits = [];

        foreach ($sets as $set) {
            foreach ($set['champions'] as $c) {
                $champions[$c['apiName']] = [
                    'name' => $c['name'],
                    'cost' => (int) $c['cost'],
                    'icon' => StaticData::assetUrl($c['squareIcon'] ?? $c['tileIcon'] ?? null),
                ];
            }

            foreach ($set['traits'] as $t) {
                $traits[$t['apiName']] = [
                    'name' => $t['name'],
                    'icon' => StaticData::assetUrl($t['icon'] ?? null),
                ];
            }
        }

        $items = collect($data['items'])
            ->reject(fn (array $i) => ($i['isAugment'] ?? false) || blank($i['name'] ?? null))
            ->mapWithKeys(fn (array $i) => [$i['apiName'] => [
                'name' => $i['name'],
                'icon' => StaticData::assetUrl($i['icon'] ?? null),
            ]])
            ->all();

        Storage::disk('local')->put(StaticData::FILE, json_encode([
            'champions' => $champions,
            'traits' => $traits,
            'items' => $items,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $this->info(sprintf(
            'Imported %d champions, %d traits and %d items.',
            count($champions),
            count($traits),
            count($items),
        ));

        return self::SUCCESS;
    }
}
