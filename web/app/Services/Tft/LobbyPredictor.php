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
 * docs in the README. A champion's contest is the expected number of
 * opponents that end up with it, compared with the usual level.
 *
 * @phpstan-type Meta array<string, array{key: string, label: string, icon: ?string, carryCost: int, games: int, share: float, avgPlacement: float, top4Rate: float, units: list<array{id: string, name: string, cost: int, icon: ?string, share: float}>}>
 */
class LobbyPredictor
{
    /** Meta weight for a player who always plays the same carry... */
    public const ALPHA_BASE = 2.0;

    /** ...plus this much for a player who never repeats a carry. */
    public const ALPHA_PER_DIVERSITY = 16.0;

    private const MIN_LISTED_PROBABILITY = 0.05;

    /** Comps need at least this many games (and 1% of all boards) to be suggested. */
    private const OPEN_COMP_MIN_GAMES = 5;

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
        /** @var Meta $meta */
        $meta = array_column($this->meta->forSet($set), null, 'key');
        $baseline = $this->meta->unitBaseline($set);
        $levelByStage = $this->playstyle->levelByStage($set);

        $players = [];
        $expected = [];
        $holders = [];
        $opponents = 0;

        foreach ($lobby as $member) {
            $games = $member['games']->values();
            $isSubject = $member['puuid'] === $subjectPuuid;
            $alpha = $this->alpha($games);
            $weight = $this->totalWeight($games);
            $name = $member['gameName'] ?? 'Unknown';

            if (! $isSubject) {
                $opponents++;

                foreach ($this->unitProbabilities($games, $baseline, $alpha) as $unitId => $p) {
                    $expected[$unitId] = ($expected[$unitId] ?? 0) + $p;
                    $holders[$unitId][$name] = $p;
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
                'likelyComps' => $this->likelyComps($this->compDistribution($games, $meta), $games, $meta),
                'actual' => $member['actual'] ? $this->actual($member['actual']) : null,
            ];
        }

        return [
            'set' => $set,
            'metaGames' => array_sum(array_column($meta, 'games')),
            'players' => $players,
            'contested' => $this->contested($expected, $holders, $baseline, $opponents),
            'openComps' => $this->openComps($meta, $expected, $baseline, $opponents),
        ];
    }

    /**
     * P(comp | player), blended with the meta the same way as units.
     *
     * @param  Collection<int, Participant>  $games  Most recent first.
     * @param  Meta  $meta
     * @return array<string, float>
     */
    public function compDistribution(Collection $games, array $meta): array
    {
        $alpha = $this->alpha($games);
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
     * @param  Collection<int, Participant>  $games
     */
    public function alpha(Collection $games): float
    {
        $diversity = $games->isEmpty()
            ? 1.0
            : $games->map(fn (Participant $g) => $this->keyOf($g))->unique()->count() / $games->count();

        return self::ALPHA_BASE + self::ALPHA_PER_DIVERSITY * $diversity;
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
        foreach (array_slice($distribution, 0, 4, true) as $key => $probability) {
            if ($probability < self::MIN_LISTED_PROBABILITY) {
                break;
            }

            // Label with the trait this player uses with the carry, if they have played it.
            $own = $played->get($key);
            $label = $own
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
     * Champions sorted by how many opponents are expected to end up with them.
     *
     * @param  array<string, float>  $expected
     * @param  array<string, array<string, float>>  $holders
     * @param  array<string, float>  $baseline
     * @return list<array{id: string, name: string, cost: int, icon: ?string, expectedPlayers: float, usualPlayers: float, players: list<array{name: string, weight: float}>}>
     */
    private function contested(array $expected, array $holders, array $baseline, int $opponents): array
    {
        arsort($expected);

        $rows = [];
        foreach (array_slice($expected, 0, 24, true) as $id => $value) {
            $champion = $this->static->champion($id);
            $players = $holders[$id] ?? [];
            arsort($players);
            $top = array_slice($players, 0, 3, true);

            $rows[] = [
                'id' => $id,
                'name' => $champion['name'],
                'cost' => $champion['cost'],
                'icon' => $champion['icon'],
                'expectedPlayers' => round($value, 2),
                'usualPlayers' => round(($baseline[$id] ?? 0) * $opponents, 2),
                'players' => array_map(
                    fn (string $name, float $weight) => ['name' => $name, 'weight' => round($weight, 2)],
                    array_keys($top),
                    array_values($top),
                ),
            ];
        }

        return $rows;
    }

    /**
     * Well-placing comps whose core champions the opponents are least likely to take.
     *
     * @param  Meta  $meta
     * @param  array<string, float>  $expected
     * @param  array<string, float>  $baseline
     * @return list<array<string, mixed>>
     */
    private function openComps(array $meta, array $expected, array $baseline, int $opponents): array
    {
        $minGames = max(self::OPEN_COMP_MIN_GAMES, (int) ceil(array_sum(array_column($meta, 'games')) * 0.01));
        $rows = [];

        foreach ($meta as $key => $comp) {
            if ($comp['games'] < $minGames || $comp['avgPlacement'] > 4.4) {
                continue;
            }

            $core = array_values(array_filter($comp['units'], fn (array $u) => $u['share'] >= MetaComps::CORE_UNIT_SHARE));
            if ($core === []) {
                continue;
            }

            $contest = array_sum(array_map(fn (array $u) => $expected[$u['id']] ?? 0, $core)) / count($core);
            $usual = array_sum(array_map(fn (array $u) => ($baseline[$u['id']] ?? 0) * $opponents, $core)) / count($core);

            $rows[] = [
                'key' => $key,
                'label' => $comp['label'],
                'icon' => $comp['icon'],
                'games' => $comp['games'],
                'avgPlacement' => $comp['avgPlacement'],
                'top4Rate' => $comp['top4Rate'],
                'unitContest' => round($contest, 2),
                'usualContest' => round($usual, 2),
                'units' => array_slice($core, 0, 8),
            ];
        }

        usort($rows, fn (array $a, array $b) => [$a['unitContest'], $a['avgPlacement']] <=> [$b['unitContest'], $b['avgPlacement']]);

        return array_slice($rows, 0, 6);
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
