<?php

namespace App\Services\Tft;

/**
 * Rank brackets (config/tft.php): which tiers they contain and which
 * bracket a lobby belongs to.
 */
class RankBracket
{
    /** Tiers from high to low, to find the median player of a lobby. */
    private const ORDER = ['CHALLENGER', 'GRANDMASTER', 'MASTER', 'DIAMOND', 'EMERALD', 'PLATINUM', 'GOLD', 'SILVER', 'BRONZE', 'IRON'];

    /**
     * @return list<string>
     */
    public static function tiers(string $bracket): array
    {
        $tiers = config("tft.brackets.{$bracket}", []);

        return is_array($tiers) ? array_values(array_map(strval(...), $tiers)) : [];
    }

    public static function forTier(?string $tier): ?string
    {
        if ($tier === null) {
            return null;
        }

        foreach ((array) config('tft.brackets', []) as $bracket => $tiers) {
            if (in_array(strtoupper($tier), (array) $tiers, true)) {
                return (string) $bracket;
            }
        }

        return null;
    }

    /**
     * The bracket of the median ranked player; null when nobody is ranked.
     *
     * @param  list<?string>  $tiers
     */
    public static function forLobby(array $tiers): ?string
    {
        $positions = [];
        foreach ($tiers as $tier) {
            $position = $tier === null ? false : array_search(strtoupper($tier), self::ORDER, true);
            if ($position !== false) {
                $positions[] = $position;
            }
        }

        if ($positions === []) {
            return null;
        }

        sort($positions);

        return self::forTier(self::ORDER[$positions[intdiv(count($positions), 2)]]);
    }

    public static function label(string $bracket): string
    {
        return match ($bracket) {
            'master+' => 'Master+',
            'platinum-' => 'Platinum and below',
            default => ucfirst($bracket),
        };
    }
}
