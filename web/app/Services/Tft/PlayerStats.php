<?php

namespace App\Services\Tft;

use App\Models\Participant;
use Illuminate\Support\Collection;

/**
 * Aggregates one player's own results. Only the player's own games are used,
 * and augments are never included (Riot doesn't allow augment placement stats).
 */
class PlayerStats
{
    public function __construct(private readonly StaticData $static) {}

    /**
     * @param  Collection<int, Participant>  $games
     * @return array{games: int, avgPlacement: float|null, top4Rate: float|null, winRate: float|null, avgLevel: float|null, placements: list<int>}
     */
    public function summary(Collection $games): array
    {
        $count = $games->count();

        $placements = array_fill(1, 8, 0);
        foreach ($games as $game) {
            $placements[$game->placement]++;
        }

        return [
            'games' => $count,
            'avgPlacement' => $count ? round($games->avg('placement'), 2) : null,
            'top4Rate' => $count ? round($games->where('placement', '<=', 4)->count() / $count * 100, 1) : null,
            'winRate' => $count ? round($games->where('placement', 1)->count() / $count * 100, 1) : null,
            'avgLevel' => $count ? round($games->avg('level'), 1) : null,
            'placements' => array_values($placements),
        ];
    }

    /**
     * Per champion: how often you played it and how you placed with it.
     *
     * @param  Collection<int, Participant>  $games
     * @return list<array<string, mixed>>
     */
    public function units(Collection $games, int $limit = 15): array
    {
        $rows = [];

        foreach ($games as $game) {
            // A unit held twice (e.g. two 2★ copies) counts once per game.
            $seen = [];
            foreach ($game->units as $unit) {
                $id = $unit['character_id'];
                $rows[$id] ??= ['placements' => [], 'stars' => []];
                $rows[$id]['stars'][] = $unit['tier'];
                if (! isset($seen[$id])) {
                    $rows[$id]['placements'][] = $game->placement;
                    $seen[$id] = true;
                }
            }
        }

        return $this->rank($rows, $limit, fn (string $id, array $row) => [
            ...$this->static->champion($id),
            'avgStars' => round(array_sum($row['stars']) / count($row['stars']), 1),
        ]);
    }

    /**
     * Per active trait: how often you ran it and how you placed with it.
     *
     * @param  Collection<int, Participant>  $games
     * @return list<array<string, mixed>>
     */
    public function traits(Collection $games, int $limit = 15): array
    {
        $rows = [];

        foreach ($games as $game) {
            foreach ($game->activeTraits() as $trait) {
                $rows[$trait['name']]['placements'][] = $game->placement;
            }
        }

        return $this->rank($rows, $limit, fn (string $id) => $this->static->trait($id));
    }

    /**
     * @param  array<string, array{placements: list<int>}>  $rows
     * @param  callable(string, array<string, mixed>): array<string, mixed>  $describe
     * @return list<array<string, mixed>>
     */
    private function rank(array $rows, int $limit, callable $describe): array
    {
        return array_values(collect($rows)
            ->map(function (array $row, string $id) use ($describe) {
                $placements = collect($row['placements']);

                return [
                    'id' => $id,
                    ...$describe($id, $row),
                    'games' => $placements->count(),
                    'avgPlacement' => round($placements->avg(), 2),
                    'top4Rate' => round($placements->filter(fn (int $p) => $p <= 4)->count() / $placements->count() * 100, 1),
                ];
            })
            ->sortBy([['games', 'desc'], ['avgPlacement', 'asc']])
            ->take($limit)
            ->all());
    }
}
