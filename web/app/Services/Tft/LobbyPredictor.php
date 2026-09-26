<?php

namespace App\Services\Tft;

use App\Models\Participant;
use Illuminate\Support\Collection;

/**
 * Predicts which champions and comps the players in a lobby will go for.
 *
 * For every player, the chance that a champion ends up on their board is a
 * blend of their own recent boards (recent games weigh more) and how common
 * the champion is overall:
 *
 *     P(unit | player) = (sum of recency weights of boards with the unit + a * meta share)
 *                        / (sum of all recency weights + a)
 *
 * The meta weight "a" grows with how many different carries the player uses:
 * someone who forces one comp is predicted from their own history, someone
 * who plays something new every game mostly from the meta. Backtested on
 * Challenger games this beat both "history only" and "meta only"; see
 * docs in the README.
 *
 * Contest is measured in pool copies: per opponent, P(unit on board) x the
 * copies they usually hold when they have it (1★ = 1, 2★ = 3, 3★ = 9; their
 * own history blended with the set average, so rerollers weigh heavily).
 * Summed over the opponents and divided by the pool size of the unit's cost,
 * that is the share of the pool expected to be taken, compared with the
 * usual share in this set.
 *
 * @phpstan-type Meta array<string, array{key: string, label: string, icon: ?string, carryCost: int, levelling: ?string, games: int, share: float, avgPlacement: ?float, top4Rate: ?float, players: int, enterable: bool, units: list<array{id: string, name: string, cost: int, icon: ?string, share: float, copies: float, threeStarRate: float, items: float, role: string, needed: int}>}>
 */
class LobbyPredictor
{
    private const MIN_LISTED_PROBABILITY = 0.05;

    /** Only comps that place at least this well on average are suggested... */
    private const OPEN_COMP_MAX_AVG_PLACEMENT = 4.4;

    /** ...and that make up at least this share of all matched boards (min 5 games). */
    private const OPEN_COMP_MIN_SHARE = 0.01;

    private const OPEN_COMP_MIN_GAMES = 5;

    /** "Free" champions must normally lose at least this many copies to the opponents. */
    private const FREE_MIN_USUAL_COPIES = 1.0;

    /** Weight of the set average when estimating a player's copies of a unit. */
    private const COPIES_PRIOR = 2.0;

    public function __construct(
        private readonly CompClassifier $classifier,
        private readonly Playstyle $playstyle,
        private readonly MetaComps $meta,
        private readonly StaticData $static,
    ) {}

    /**
     * @param  list<array{puuid: string, gameName: ?string, tagLine: ?string, league: list<array<string, mixed>>, games: Collection<int, Participant>, actual: ?Participant}>  $lobby
     * @return array<string, mixed>
     */
    public function predict(array $lobby, ?int $set, ?string $subjectPuuid): array
    {
        // Compare with games of the lobby's own rank bracket when we have
        // enough of them, otherwise with all games of the set.
        $lobbyBracket = RankBracket::forLobby(array_map(fn (array $m) => MatchImporter::rankedTier($m['league']), $lobby));
        $dataBracket = $lobbyBracket !== null && $this->meta->boardCount($set, $lobbyBracket) >= (int) config('tft.bracket_min_boards')
            ? $lobbyBracket
            : null;

        /** @var Meta $meta */
        $meta = array_column($this->meta->forSet($set, $dataBracket), null, 'key');
        $baseline = $this->meta->unitBaseline($set, $dataBracket);
        $copies = $this->meta->unitCopies($set, $dataBracket);
        $levelByStage = $this->playstyle->levelByStage($set);

        $players = [];
        $expected = [];
        $holders = [];
        $opponents = 0;

        foreach ($lobby as $member) {
            $games = $member['games']->values();
            $isSubject = $member['puuid'] === $subjectPuuid;
            $alpha = $this->alpha($games, $lobbyBracket);
            $weight = $this->totalWeight($games);
            $name = $member['gameName'] ?? 'Unknown';

            if (! $isSubject) {
                $opponents++;

                $held = $this->copiesWhenHeld($games, $copies);

                foreach ($this->unitProbabilities($games, $baseline, $alpha) as $unitId => $p) {
                    $unitCopies = $p * ($held[$unitId] ?? $copies[$unitId] ?? 1.0);
                    $expected[$unitId] = ($expected[$unitId] ?? 0) + $unitCopies;
                    $holders[$unitId][$name] = $unitCopies;
                }
            }

            $players[] = [
                'puuid' => $member['puuid'],
                'gameName' => $member['gameName'],
                'tagLine' => $member['tagLine'],
                'isSubject' => $isSubject,
                'rank' => $this->rank($member['league']),
                'playstyle' => $this->playstyle->profile($games, $levelByStage),
                // How much of the prediction comes from this player's own games.
                'historyWeight' => round($weight / ($weight + $alpha), 2),
                'likelyComps' => $this->likelyComps($this->compDistribution($games, $meta, $lobbyBracket), $games, $meta),
                'actual' => $member['actual'] ? $this->actual($member['actual']) : null,
            ];
        }

        $usual = $this->usualCopies($baseline, $copies, $opponents);
        $champions = $this->championRows($expected, $holders, $usual);

        return [
            'set' => $set,
            'metaGames' => array_sum(array_column($meta, 'games')),
            'players' => $players,
            'contested' => $this->contested($champions),
            'free' => $this->free($champions, $meta),
            'openComps' => $openComps = $this->openComps($meta, $expected, $usual, array_keys($baseline)),
            // Whether any comp is clearly (5%+) easier than in a usual lobby.
            'anyOpen' => collect($openComps)->contains(fn (array $c) => $c['difficulty'] !== null && $c['difficulty'] <= 0.95),
            'bracket' => [
                'lobby' => $lobbyBracket !== null ? RankBracket::label($lobbyBracket) : null,
                'comparedWith' => $dataBracket !== null ? RankBracket::label($dataBracket) : null,
                'boards' => $this->meta->boardCount($set, $dataBracket),
            ],
        ];
    }

    /**
     * P(comp | player), blended with the meta the same way as units.
     *
     * @param  Collection<int, Participant>  $games  Most recent first.
     * @param  Meta  $meta
     * @return array<string, float>
     */
    public function compDistribution(Collection $games, array $meta, ?string $bracket = null): array
    {
        $alpha = $this->alpha($games, $bracket);
        $distribution = [];

        foreach ($meta as $key => $comp) {
            $distribution[$key] = $alpha * $comp['share'];
        }

        foreach ($games->values() as $i => $game) {
            $key = $this->keyOf($game);
            $distribution[$key] = ($distribution[$key] ?? 0) + Playstyle::RECENCY_DECAY ** $i;
        }

        $sum = array_sum($distribution);

        return $sum > 0 ? array_map(fn (float $v) => $v / $sum, $distribution) : [];
    }

    /**
     * P(unit ends on this player's board) for every known unit.
     *
     * @param  Collection<int, Participant>  $games  Most recent first.
     * @param  array<string, float>  $baseline
     * @return array<string, float>
     */
    public function unitProbabilities(Collection $games, array $baseline, float $alpha): array
    {
        $seen = [];
        foreach ($games->values() as $i => $game) {
            foreach (array_unique(array_column($game->units, 'character_id')) as $unitId) {
                $seen[$unitId] = ($seen[$unitId] ?? 0) + Playstyle::RECENCY_DECAY ** $i;
            }
        }

        $total = $this->totalWeight($games) + $alpha;
        $probabilities = [];

        foreach (array_keys($baseline + $seen) as $unitId) {
            $probabilities[$unitId] = (($seen[$unitId] ?? 0) + $alpha * ($baseline[$unitId] ?? 0)) / $total;
        }

        return $probabilities;
    }

    /**
     * Copies this player usually holds of each unit when it is on their board:
     * their own recency-weighted average, pulled towards the set average.
     *
     * @param  Collection<int, Participant>  $games  Most recent first.
     * @param  array<string, float>  $average  Set average copies when held.
     * @return array<string, float>
     */
    public function copiesWhenHeld(Collection $games, array $average): array
    {
        $copies = [];
        $weights = [];

        foreach ($games->values() as $i => $game) {
            $w = Playstyle::RECENCY_DECAY ** $i;
            foreach ($game->copiesByUnit() as $unitId => $count) {
                $copies[$unitId] = ($copies[$unitId] ?? 0) + $w * $count;
                $weights[$unitId] = ($weights[$unitId] ?? 0) + $w;
            }
        }

        $held = [];
        foreach ($copies as $unitId => $weighted) {
            $prior = $average[$unitId] ?? 1.0;
            $held[$unitId] = ($weighted + self::COPIES_PRIOR * $prior) / ($weights[$unitId] + self::COPIES_PRIOR);
        }

        return $held;
    }

    /**
     * Copies the opponents usually take of each unit in this set.
     *
     * @param  array<string, float>  $baseline  Share of boards with the unit.
     * @param  array<string, float>  $copies  Average copies when held.
     * @return array<string, float>
     */
    private function usualCopies(array $baseline, array $copies, int $opponents): array
    {
        $usual = [];
        foreach ($baseline as $unitId => $share) {
            $usual[$unitId] = $share * ($copies[$unitId] ?? 1.0) * $opponents;
        }

        return $usual;
    }

    /**
     * Copies of a unit in the shared pool, or null for units outside the shop.
     */
    private function poolSize(int $cost): ?int
    {
        $size = config("tft.pool_sizes.{$cost}");

        return is_numeric($size) ? (int) $size : null;
    }

    /**
     * @param  Collection<int, Participant>  $games
     */
    public function alpha(Collection $games, ?string $bracket = null): float
    {
        $diversity = $games->isEmpty()
            ? 1.0
            : $games->map(fn (Participant $g) => $this->keyOf($g))->unique()->count() / $games->count();

        $weights = config("tft.prediction_alpha.{$bracket}") ?? config('tft.prediction_alpha.default');

        return (float) $weights['base'] + (float) $weights['per_diversity'] * $diversity;
    }

    /**
     * @param  Collection<int, Participant>  $games
     */
    private function totalWeight(Collection $games): float
    {
        $total = 0.0;
        for ($i = 0; $i < $games->count(); $i++) {
            $total += Playstyle::RECENCY_DECAY ** $i;
        }

        return $total;
    }

    private function keyOf(Participant $game): string
    {
        return $this->classifier->classify($game->traits, $game->units)['key'];
    }

    /**
     * @param  array<string, float>  $distribution
     * @param  Collection<int, Participant>  $games
     * @param  Meta  $meta
     * @return list<array{key: string, label: string, icon: ?string, probability: float, playedBefore: int}>
     */
    private function likelyComps(array $distribution, Collection $games, array $meta): array
    {
        arsort($distribution);
        $played = $games->groupBy(fn (Participant $g) => $this->keyOf($g));

        $comps = [];
        foreach ($distribution as $key => $probability) {
            if ($probability < self::MIN_LISTED_PROBABILITY || count($comps) === 4) {
                break;
            }

            if (! $this->classifier->isComp($key)) {
                continue;
            }

            // Imported comps have a fixed name; carry-based ones are labelled
            // with the trait this player uses with that carry.
            $own = $played->get($key);
            $label = $own && ! isset($meta[$key])
                ? $this->classifier->describe($key, $this->meta->mostCommonTrait($own))['label']
                : ($meta[$key]['label'] ?? $this->classifier->describe($key)['label']);

            $comps[] = [
                'key' => $key,
                'label' => $label,
                'icon' => $this->classifier->describe($key)['icon'],
                'probability' => round($probability, 3),
                'playedBefore' => $own?->count() ?? 0,
            ];
        }

        return $comps;
    }

    /**
     * @return array{key: string, label: string, icon: ?string, placement: int}
     */
    private function actual(Participant $game): array
    {
        $comp = $this->classifier->classify($game->traits, $game->units);
        $described = $this->classifier->describe($comp['key'], $comp['trait']);

        return ['key' => $comp['key'], 'label' => $described['label'], 'icon' => $described['icon'], 'placement' => $game->placement];
    }

    /**
     * Champions the opponents take at least as much of as usual, sorted by
     * the share of their pool expected to be taken.
     *
     * @param  list<array{id: string, name: string, cost: int, icon: ?string, poolSize: int, expectedCopies: float, usualCopies: float, players: list<array{name: string, copies: float}>}>  $rows
     * @return list<array{id: string, name: string, cost: int, icon: ?string, poolSize: int, expectedCopies: float, usualCopies: float, players: list<array{name: string, copies: float}>}>
     */
    private function contested(array $rows): array
    {
        // Every champion taken at least as much as usual; the ones taken less
        // are in the "free" list, so no champion falls between the two.
        $rows = array_values(array_filter(
            $rows,
            fn (array $row) => $row['expectedCopies'] > 0 && $row['expectedCopies'] >= $row['usualCopies'],
        ));

        usort($rows, fn (array $a, array $b) => $b['expectedCopies'] / $b['poolSize'] <=> $a['expectedCopies'] / $a['poolSize']);

        return $rows;
    }

    /**
     * Champions that matter (a 3★ target or carry in a real comp, normally
     * contested) but that the opponents are expected to take less of than
     * usual. Most "freer than usual" first.
     *
     * @param  list<array{id: string, name: string, cost: int, icon: ?string, poolSize: int, expectedCopies: float, usualCopies: float, players: list<array{name: string, copies: float}>}>  $rows
     * @param  Meta  $meta
     * @return list<array<string, mixed>>
     */
    private function free(array $rows, array $meta): array
    {
        // Which proven comps each champion is a key unit of (niche lines don't count).
        $minGames = $this->minProvenGames($meta);
        $keyIn = [];
        foreach ($meta as $comp) {
            if (! $comp['enterable'] || $comp['games'] < $minGames) {
                continue;
            }

            foreach ($comp['units'] as $unit) {
                if ($unit['role'] !== 'filler') {
                    $keyIn[$unit['id']][] = $comp['label'];
                }
            }
        }

        $free = [];
        foreach ($rows as $row) {
            // Only champions people actually want, and that are normally taken.
            if (! isset($keyIn[$row['id']]) || $row['usualCopies'] < self::FREE_MIN_USUAL_COPIES) {
                continue;
            }

            $ratio = $row['expectedCopies'] / $row['usualCopies'];

            if ($ratio < 1) {
                $free[] = [...$row, 'ratio' => round($ratio, 2), 'keyIn' => array_slice($keyIn[$row['id']], 0, 3)];
            }
        }

        usort($free, fn (array $a, array $b) => $a['ratio'] <=> $b['ratio']);

        return $free;
    }

    /**
     * Games a comp needs before we trust it: 1% of all matched boards, min 5.
     *
     * @param  Meta  $meta
     */
    private function minProvenGames(array $meta): float
    {
        return max(self::OPEN_COMP_MIN_GAMES, array_sum(array_column($meta, 'games')) * self::OPEN_COMP_MIN_SHARE);
    }

    /**
     * One row per shop champion with the copies expected and usually taken.
     *
     * @param  array<string, float>  $expected  Expected copies taken.
     * @param  array<string, array<string, float>>  $holders  Expected copies per opponent.
     * @param  array<string, float>  $usual  Usual copies taken.
     * @return list<array{id: string, name: string, cost: int, icon: ?string, poolSize: int, expectedCopies: float, usualCopies: float, players: list<array{name: string, copies: float}>}>
     */
    private function championRows(array $expected, array $holders, array $usual): array
    {
        $rows = [];

        foreach ($expected as $id => $value) {
            $champion = $this->static->champion($id);
            $pool = $this->poolSize($champion['cost']);

            // Summons and other units outside the shop have no pool.
            if ($pool === null) {
                continue;
            }

            $players = $holders[$id] ?? [];
            arsort($players);
            $top = array_slice($players, 0, 3, true);

            $rows[] = [
                'id' => $id,
                'name' => $champion['name'],
                'cost' => $champion['cost'],
                'icon' => $champion['icon'],
                'poolSize' => $pool,
                'expectedCopies' => round($value, 1),
                'usualCopies' => round($usual[$id] ?? 0, 1),
                'players' => array_map(
                    fn (string $name, float $copies) => ['name' => $name, 'copies' => round($copies, 1)],
                    array_keys($top),
                    array_values($top),
                ),
            ];
        }

        return $rows;
    }

    /**
     * Well-placing comps whose key champions are easiest to hit in this lobby.
     *
     * Only a comp's key units count (3★ targets and carries, see
     * MetaComps::withRoles); fillers are played for traits and are easy to
     * find. For each key unit we estimate the shops needed to find the copies
     * the comp needs, at the level the comp rolls at, given what the
     * opponents are expected to take. Comps are ranked by how that compares
     * with a usual lobby.
     *
     * @param  Meta  $meta
     * @param  array<string, float>  $expected  Expected copies taken by the opponents.
     * @param  array<string, float>  $usual  Copies usually taken.
     * @param  list<string>  $setUnits  All units seen in this set.
     * @return list<array<string, mixed>>
     */
    private function openComps(array $meta, array $expected, array $usual, array $setUnits): array
    {
        $rows = [];
        $minGames = $this->minProvenGames($meta);
        $remainingNow = $this->remainingPerCost($setUnits, $expected);
        $remainingUsual = $this->remainingPerCost($setUnits, $usual);

        foreach ($meta as $key => $comp) {
            // Only suggest real, repeatable lines with a proven placement:
            // enough games of our own, and placing well. Rarely played lines
            // are too niche to rely on.
            if (! $comp['enterable']
                || $comp['games'] < $minGames
                || $comp['avgPlacement'] === null
                || $comp['avgPlacement'] > self::OPEN_COMP_MAX_AVG_PLACEMENT) {
                continue;
            }

            $level = self::rollLevel($comp['levelling']);
            $keyUnits = [];

            foreach ($comp['units'] as $unit) {
                $pool = $this->poolSize($unit['cost']);

                if ($unit['role'] === 'filler' || $unit['needed'] === 0 || $pool === null) {
                    continue;
                }

                $takenNow = $expected[$unit['id']] ?? 0.0;
                $takenUsual = $usual[$unit['id']] ?? 0.0;

                $keyUnits[] = [
                    'id' => $unit['id'],
                    'name' => $unit['name'],
                    'cost' => $unit['cost'],
                    'icon' => $unit['icon'],
                    'role' => $unit['role'],
                    'needed' => $unit['needed'],
                    'poolSize' => $pool,
                    'left' => round(max(0.0, $pool - $takenNow), 1),
                    // Units that can't show up at the comp's roll level (e.g. a
                    // 5-cost in a level 5 reroll comp) are found later, at the
                    // first level where they can.
                    'level' => $unitLevel = self::firstLevelWithOdds($unit['cost'], $level),
                    'rolls' => $this->rollsToHit($unit['needed'], $pool - $takenNow, $remainingNow[$unit['cost']] ?? 0.0, $unit['cost'], $unitLevel),
                    'usualRolls' => $this->rollsToHit($unit['needed'], $pool - $takenUsual, $remainingUsual[$unit['cost']] ?? 0.0, $unit['cost'], $unitLevel),
                ];
            }

            if ($keyUnits === []) {
                continue;
            }

            // 3★ targets first, then carries; most needed copies first.
            usort($keyUnits, fn (array $a, array $b) => [$a['role'] !== 'target', -$a['needed'], $a['name']] <=> [$b['role'] !== 'target', -$b['needed'], $b['name']]);

            // You roll for all key units at once, so the slowest one decides.
            // A unit with fewer copies left than needed blocks the comp.
            $rolls = array_column($keyUnits, 'rolls');
            $usualRolls = array_column($keyUnits, 'usualRolls');
            $total = in_array(null, $rolls, true) ? null : max($rolls);
            $usualTotal = in_array(null, $usualRolls, true) ? null : max($usualRolls);

            $rows[] = [
                'key' => $key,
                'label' => $comp['label'],
                'icon' => $comp['icon'],
                'levelling' => $comp['levelling'],
                'rollLevel' => $level,
                'games' => $comp['games'],
                'avgPlacement' => $comp['avgPlacement'],
                'top4Rate' => $comp['top4Rate'],
                'rolls' => $total,
                'usualRolls' => $usualTotal,
                // Below 1: easier than in a usual lobby.
                'difficulty' => $total !== null && $usualTotal ? round($total / $usualTotal, 2) : null,
                'keyUnits' => $keyUnits,
            ];
        }

        // Small differences in difficulty are noise: group in steps of 10%,
        // then prefer the comp that places best.
        $bucket = fn (array $row) => $row['difficulty'] === null ? INF : round($row['difficulty'], 1);
        usort($rows, fn (array $a, array $b) => [$bucket($a), $a['avgPlacement']] <=> [$bucket($b), $b['avgPlacement']]);

        // All proven comps, best first; the page shows the top few and can expand.
        return $rows;
    }

    /**
     * Average shops (rerolls) needed to find $needed copies of one unit.
     *
     * Per shop slot, the chance to see this unit is the level's odds for its
     * cost, times its share of all remaining copies of that cost. Copies of
     * other units taken by opponents make it easier; copies of this unit
     * make it harder. Returns null when fewer copies are left than needed.
     */
    private function rollsToHit(int $needed, float $left, float $remainingOfCost, int $cost, int $level): ?float
    {
        if ($left < $needed || $remainingOfCost <= 0) {
            return null;
        }

        $odds = (float) config("tft.shop_odds.{$level}.{$cost}", 0) / 100;
        $perShop = (int) config('tft.shop_slots', 5) * $odds * ($left / $remainingOfCost);

        return $perShop > 0 ? round($needed / $perShop, 1) : null;
    }

    /**
     * Copies left in the pool per cost, after the given copies are taken.
     *
     * @param  list<string>  $setUnits
     * @param  array<string, float>  $taken
     * @return array<int, float>
     */
    private function remainingPerCost(array $setUnits, array $taken): array
    {
        $remaining = [];

        foreach ($setUnits as $unitId) {
            $cost = $this->static->champion($unitId)['cost'];
            $pool = $this->poolSize($cost);

            if ($pool !== null) {
                $remaining[$cost] = ($remaining[$cost] ?? 0.0) + max(0.0, $pool - ($taken[$unitId] ?? 0.0));
            }
        }

        return $remaining;
    }

    /**
     * The first level from $level up where units of this cost can appear in the shop.
     */
    public static function firstLevelWithOdds(int $cost, int $level): int
    {
        for ($l = $level; $l <= 10; $l++) {
            if ((float) config("tft.shop_odds.{$l}.{$cost}", 0) > 0) {
                return $l;
            }
        }

        return $level;
    }

    /**
     * The level a comp rolls at: "lvl 7" -> 7, "Fast 8" -> 8, "Fast 9" -> 9.
     */
    public static function rollLevel(?string $levelling): int
    {
        return $levelling !== null && preg_match('/(\d+)/', $levelling, $m) ? (int) $m[1] : 8;
    }

    /**
     * @param  list<array<string, mixed>>  $league
     * @return array{tier: ?string, division: ?string, lp: int}|null
     */
    private function rank(array $league): ?array
    {
        foreach ($league as $entry) {
            if (($entry['queueType'] ?? null) === 'RANKED_TFT') {
                return [
                    'tier' => is_string($entry['tier'] ?? null) ? $entry['tier'] : null,
                    'division' => is_string($entry['rank'] ?? null) ? $entry['rank'] : null,
                    'lp' => is_numeric($entry['leaguePoints'] ?? null) ? (int) $entry['leaguePoints'] : 0,
                ];
            }
        }

        return null;
    }
}
