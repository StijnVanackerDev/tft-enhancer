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
     * Traits that were actually active (Riot also lists inactive ones with style 0).
     *
     * @return list<array{name: string, num_units: int, style: int, tier_current: int, tier_total: int}>
     */
    public function activeTraits(): array
    {
        return array_values(array_filter($this->traits, fn (array $t) => $t['style'] > 0));
    }
}
