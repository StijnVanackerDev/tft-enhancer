<?php

namespace App\Services\Tft;

/**
 * Identifies the comp a final board represents by its carry: the unit holding
 * the most items (ties go to the more expensive, higher-star unit).
 *
 * Backtesting on Challenger games showed that "trait + carry" splits one comp
 * into many near-identical variants (Juggernaut Ashe, Hunter Ashe, ...),
 * which makes every player look random. The carry alone groups them; the most
 * common main trait is only used in the label.
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
        $carry = collect($units)
            ->sortByDesc(fn (array $u) => [count($u['items']), $this->static->champion($u['character_id'])['cost'], $u['tier']])
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
