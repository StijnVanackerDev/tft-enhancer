<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One player's result in one match.
 *
 * @property int $id
 * @property int $tft_match_id
 * @property string $puuid
 * @property string|null $game_name
 * @property string|null $tag_line
 * @property int $placement
 * @property int $level
 * @property int $gold_left
 * @property int $last_round
 * @property int $damage_to_players
 * @property int $players_eliminated
 * @property list<array{name: string, num_units: int, style: int, tier_current: int, tier_total: int}> $traits
 * @property list<array{character_id: string, tier: int, rarity: int, items: list<string>}> $units
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read TftMatch $match
 */
#[Fillable([
    'tft_match_id', 'puuid', 'game_name', 'tag_line', 'placement', 'level', 'gold_left',
    'last_round', 'damage_to_players', 'players_eliminated', 'traits', 'units',
])]
class Participant extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'traits' => 'array',
            'units' => 'array',
        ];
    }

    /**
     * @return BelongsTo<TftMatch, $this>
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(TftMatch::class, 'tft_match_id');
    }

    /**
     * Copies of a unit a star level takes out of the pool: 1★ = 1, 2★ = 3,
     * 3★ = 9 (4★ units, where a set has them, are counted as 3★).
     */
    public static function copiesForStars(int $stars): int
    {
        return 3 ** (max(1, min(3, $stars)) - 1);
    }

    /**
     * Pool copies held per unit on this final board (a unit fielded twice counts twice).
     *
     * @return array<string, int>
     */
    public function copiesByUnit(): array
    {
        $copies = [];

        foreach ($this->units as $unit) {
            $copies[$unit['character_id']] = ($copies[$unit['character_id']] ?? 0) + self::copiesForStars($unit['tier']);
        }

        return $copies;
    }

    /**
     * Traits that were actually active (Riot also lists inactive ones with style 0).
     *
     * @return list<array{name: string, num_units: int, style: int, tier_current: int, tier_total: int}>
     */
    public function activeTraits(): array
    {
        return array_values(array_filter($this->traits, fn (array $t) => $t['style'] > 0));
    }
}
