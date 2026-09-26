<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A known comp of a set (name, units, traits), imported with
 * `php artisan tft:import-comps`. Boards are matched to these definitions;
 * all statistics are computed from our own match data.
 *
 * @property int $id
 * @property int $set_number
 * @property string $external_id
 * @property string $name
 * @property list<string> $units
 * @property list<string> $carries
 * @property list<array{name: string, tier: int}> $traits
 * @property string|null $levelling
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['set_number', 'external_id', 'name', 'units', 'carries', 'traits', 'levelling'])]
class CompDefinition extends Model
{
    /** Prefix that marks a comp key as an imported definition. */
    public const KEY_PREFIX = 'comp:';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'units' => 'array',
            'carries' => 'array',
            'traits' => 'array',
        ];
    }

    /**
     * The comp key used everywhere boards are grouped ("comp:12").
     */
    public function compKey(): string
    {
        return self::KEY_PREFIX.$this->id;
    }
}
