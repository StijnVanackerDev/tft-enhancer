<?php

namespace App\Services\Tft;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Names and icons for champions, traits and items, imported from Community
 * Dragon by `php artisan tft:import-static`. Falls back to a readable name
 * derived from the API name when the data is missing or outdated.
 */
class StaticData
{
    public const FILE = 'tft-static.json';

    public const ASSET_BASE = 'https://raw.communitydragon.org/latest/game/';

    /**
     * Raw decoded file: section ("champions", "traits", "items") -> API name -> entry.
     *
     * @var array<string, mixed>|null
     */
    private ?array $data = null;

    /**
     * @return array{name: string, cost: int, icon: ?string}
     */
    public function champion(string $apiName): array
    {
        $entry = $this->entry('champions', $apiName);

        return [
            'name' => $this->string($entry, 'name') ?? self::readableName($apiName),
            'cost' => is_numeric($entry['cost'] ?? null) ? (int) $entry['cost'] : 0,
            'icon' => $this->string($entry, 'icon'),
        ];
    }

    /**
     * @return array{name: string, icon: ?string}
     */
    public function trait(string $apiName): array
    {
        $entry = $this->entry('traits', $apiName);

        return [
            'name' => $this->string($entry, 'name') ?? self::readableName($apiName),
            'icon' => $this->string($entry, 'icon'),
        ];
    }

    /**
     * @return array{name: string, icon: ?string}
     */
    public function item(string $apiName): array
    {
        $entry = $this->entry('items', $apiName);

        return [
            'name' => $this->string($entry, 'name') ?? self::readableName($apiName),
            'icon' => $this->string($entry, 'icon'),
        ];
    }

    public function isLoaded(): bool
    {
        return ($this->load()['champions'] ?? []) !== [];
    }

    /**
     * "TFT17_MissFortune" -> "Miss Fortune", "TFT_Item_InfinityEdge" -> "Infinity Edge".
     */
    public static function readableName(string $apiName): string
    {
        $name = Str::afterLast($apiName, '_');

        return Str::headline($name);
    }

    public static function assetUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        return self::ASSET_BASE.preg_replace('/\.(tex|dds)$/', '.png', strtolower($path));
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $section, string $apiName): array
    {
        $entries = $this->load()[$section] ?? null;
        $entry = is_array($entries) ? ($entries[$apiName] ?? null) : null;

        return is_array($entry) ? $entry : [];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function string(array $entry, string $key): ?string
    {
        return is_string($entry[$key] ?? null) && $entry[$key] !== '' ? $entry[$key] : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        $disk = Storage::disk('local');
        $decoded = $disk->exists(self::FILE) ? json_decode((string) $disk->get(self::FILE), true) : null;

        return $this->data = is_array($decoded) ? $decoded : [];
    }
}
