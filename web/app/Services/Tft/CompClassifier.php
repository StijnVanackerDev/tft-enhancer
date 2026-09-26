<?php

namespace App\Services\Tft;

/**
 * Identifies the comp a final board represents by its carry: the unit costing
 * 1-4 gold that holds the most items (ties go to higher stars, then cost).
 *
 * Tested on real Set 18 boards:
 *  - "trait + carry" splits one comp into many near-identical variants;
 *  - "any carry" turns 5-cost capstones (Lux, Gnar, Kennen, ...) into fake
 *    comps, because late boards often put items on whatever 5-cost they hit.
 * The cheapest itemised line carry is what players actually aim for: it put
 * 68% of boards into well-defined comps, against 50% for "any carry".
 */
class CompClassifier
{
    public function __construct(private readonly StaticData $static) {}

    /**
     * @param  list<array{name: string, num_units: int, style: int, tier_current: int, tier_total: int}>  $traits
     * @param  list<array{character_id: string, tier: int, rarity: int, items: list<string>}>  $units
     * @return array{key: string, trait: ?string, carry: ?string, carryStars: int}
     */
    public function classify(array $traits, array $units): array
    {
        $byInvestment = fn (array $u) => [count($u['items']), $u['tier'], $this->static->champion($u['character_id'])['cost']];
        $lineUnits = collect($units)->filter(function (array $u) {
            $cost = $this->static->champion($u['character_id'])['cost'];

            return $cost >= 1 && $cost <= 4;
        });

        // Boards of only 5-costs (or unknown units) fall back to any unit.
        $carry = ($lineUnits->isNotEmpty() ? $lineUnits : collect($units))
            ->sortByDesc($byInvestment)
            ->first();

        $trait = collect($traits)
            ->filter(fn (array $t) => $t['style'] > 0 && $t['tier_total'] > 1)
            ->sortByDesc(fn (array $t) => [$t['num_units'], $t['style']])
            ->first();

        return [
            'key' => $carry['character_id'] ?? '-',
            'trait' => $trait['name'] ?? null,
            'carry' => $carry['character_id'] ?? null,
            'carryStars' => $carry['tier'] ?? 0,
        ];
    }

    /**
     * @param  string|null  $traitId  The comp's most common main trait, for the label.
     * @return array{label: string, icon: ?string, carryCost: int}
     */
    public function describe(string $key, ?string $traitId = null): array
    {
        $carry = $key !== '-' ? $this->static->champion($key) : null;
        $trait = $traitId !== null ? $this->static->trait($traitId)['name'] : null;

        return [
            'label' => trim(($trait ?? '').' '.($carry['name'] ?? '')) ?: 'Unknown comp',
            'icon' => $carry['icon'] ?? null,
            'carryCost' => $carry['cost'] ?? 0,
        ];
    }
}
