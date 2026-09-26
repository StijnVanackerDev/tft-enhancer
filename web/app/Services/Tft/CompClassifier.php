<?php

namespace App\Services\Tft;

use App\Models\CompDefinition;
use Illuminate\Support\Collection;

/**
 * Decides which comp a final board represents.
 *
 * When comp definitions are imported (`tft:import-comps`), a board belongs to
 * the definition whose units it contains most of (at least half); boards that
 * match none are situational ("-"). Without definitions (e.g. a brand new
 * set) it falls back to naming the comp after its carry: the 1-4 cost unit
 * holding the most items, since 5-costs are capstones rather than comps.
 */
class CompClassifier
{
    /** Share of a definition's units a board needs to count as that comp. */
    public const MIN_MATCH = 0.5;

    /** Key for boards that don't match any known comp. */
    public const SITUATIONAL = '-';

    /** @var Collection<int, CompDefinition>|null */
    private ?Collection $definitions = null;

    /** @var array<string, true> */
    private array $definitionUnits = [];

    public function __construct(private readonly StaticData $static) {}

    /**
     * @param  list<array{name: string, num_units: int, style: int, tier_current: int, tier_total: int}>  $traits
     * @param  list<array{character_id: string, tier: int, rarity: int, items: list<string>}>  $units
     * @return array{key: string, trait: ?string, carry: ?string, carryStars: int}
     */
    public function classify(array $traits, array $units): array
    {
        $carry = $this->carry($units);

        $trait = collect($traits)
            ->filter(fn (array $t) => $t['style'] > 0 && $t['tier_total'] > 1)
            ->sortByDesc(fn (array $t) => [$t['num_units'], $t['style']])
            ->first();

        return [
            'key' => $this->keyFor($units, $carry),
            'trait' => $trait['name'] ?? null,
            'carry' => $carry['character_id'] ?? null,
            'carryStars' => $carry['tier'] ?? 0,
        ];
    }

    /**
     * @param  string|null  $traitId  Only used for carry-based keys: the comp's most common main trait.
     * @return array{label: string, icon: ?string, carryCost: int, levelling: ?string}
     */
    public function describe(string $key, ?string $traitId = null): array
    {
        if (str_starts_with($key, CompDefinition::KEY_PREFIX)) {
            $definition = $this->definitions()->firstWhere('id', (int) substr($key, strlen(CompDefinition::KEY_PREFIX)));
            $carry = $definition ? $this->static->champion($definition->carries[0] ?? $definition->units[0]) : null;

            return [
                'label' => $definition->name ?? 'Unknown comp',
                'icon' => $carry['icon'] ?? null,
                'carryCost' => $carry['cost'] ?? 0,
                'levelling' => $definition?->levelling,
            ];
        }

        $carry = $key !== self::SITUATIONAL ? $this->static->champion($key) : null;
        $trait = $traitId !== null ? $this->static->trait($traitId)['name'] : null;

        return [
            'label' => $key === self::SITUATIONAL
                ? 'Situational board'
                : (trim(($trait ?? '').' '.($carry['name'] ?? '')) ?: 'Unknown comp'),
            'icon' => $carry['icon'] ?? null,
            'carryCost' => $carry['cost'] ?? 0,
            'levelling' => null,
        ];
    }

    public function isComp(string $key): bool
    {
        return $key !== self::SITUATIONAL;
    }

    /**
     * Imported comp definitions of the most recent set that has any.
     *
     * @return Collection<int, CompDefinition>
     */
    public function definitions(): Collection
    {
        if ($this->definitions === null) {
            $set = CompDefinition::query()->max('set_number');
            $this->definitions = $set === null
                ? collect()
                : CompDefinition::where('set_number', $set)->get();

            $this->definitionUnits = [];
            foreach ($this->definitions as $definition) {
                foreach ($definition->units as $unit) {
                    $this->definitionUnits[$unit] = true;
                }
            }
        }

        return $this->definitions;
    }

    /**
     * @param  list<array{character_id: string, tier: int, rarity: int, items: list<string>}>  $units
     * @param  array{character_id: string, tier: int, rarity: int, items: list<string>}|null  $carry
     */
    private function keyFor(array $units, ?array $carry): string
    {
        $unitIds = array_values(array_unique(array_column($units, 'character_id')));
        $definitions = $this->definitions();

        // Boards of a set we have definitions for (they share units with them).
        if ($definitions->isNotEmpty() && array_intersect_key(array_flip($unitIds), $this->definitionUnits) !== []) {
            return $this->bestDefinition($unitIds, $definitions)?->compKey() ?? self::SITUATIONAL;
        }

        return $carry['character_id'] ?? self::SITUATIONAL;
    }

    /**
     * @param  list<string>  $unitIds
     * @param  Collection<int, CompDefinition>  $definitions
     */
    private function bestDefinition(array $unitIds, Collection $definitions): ?CompDefinition
    {
        $best = null;
        $bestScore = 0.0;

        foreach ($definitions as $definition) {
            $match = count(array_intersect($definition->units, $unitIds)) / max(1, count($definition->units));

            if ($match < self::MIN_MATCH) {
                continue;
            }

            // Having the comp's named carries on the board breaks ties.
            $carries = count(array_intersect($definition->carries, $unitIds)) / max(1, count($definition->carries));
            $score = $match + 0.25 * $carries;

            if ($score > $bestScore) {
                $best = $definition;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * The 1-4 cost unit holding the most items (any unit if there is none).
     *
     * @param  list<array{character_id: string, tier: int, rarity: int, items: list<string>}>  $units
     * @return array{character_id: string, tier: int, rarity: int, items: list<string>}|null
     */
    private function carry(array $units): ?array
    {
        $byInvestment = fn (array $u) => [count($u['items']), $u['tier'], $this->static->champion($u['character_id'])['cost']];
        $lineUnits = collect($units)->filter(function (array $u) {
            $cost = $this->static->champion($u['character_id'])['cost'];

            return $cost >= 1 && $cost <= 4;
        });

        return ($lineUnits->isNotEmpty() ? $lineUnits : collect($units))->sortByDesc($byInvestment)->first();
    }
}
