<?php

namespace App\Services\Tft;

use App\Models\Participant;
use App\Models\TftMatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Comp statistics across every stored board of a set: how often each comp is
 * played, how it places, and which units it usually runs. Built from our own
 * database (searched players' lobbies and `tft:crawl-meta`), aggregated over
 * all players, so no individual player's data is exposed.
 */
class MetaComps
{
    /** Comps seen fewer times than this are too noisy to show. */
    public const MIN_GAMES = 3;

    /** A unit counts as "core" for a comp when at least this share of its boards run it. */
    public const CORE_UNIT_SHARE = 0.5;

    public function __construct(
        private readonly CompClassifier $classifier,
        private readonly StaticData $static,
    ) {}

    public function latestSet(): ?int
    {
        $set = TftMatch::query()->max('set_number');

        return $set === null ? null : (int) $set;
    }

    /**
     * @return list<array{key: string, label: string, icon: ?string, carryCost: int, games: int, share: float, avgPlacement: float, top4Rate: float, units: list<array{id: string, name: string, cost: int, icon: ?string, share: float}>}>
     */
    public function forSet(?int $set): array
    {
        if ($set === null) {
            return [];
        }

        return Cache::remember("meta-comps:{$set}", now()->addMinutes(10), function () use ($set) {
            $boards = Participant::query()
                ->whereHas('match', fn ($q) => $q->againstPlayers()->where('set_number', $set))
                ->get(['placement', 'traits', 'units']);

            $groups = $boards->groupBy(fn (Participant $p) => $this->classifier->classify($p->traits, $p->units)['key']);
            $total = max(1, $groups->filter(fn (Collection $g) => $g->count() >= self::MIN_GAMES)->sum(fn (Collection $g) => $g->count()));

            return array_values($groups
                ->filter(fn (Collection $g, string $key) => $g->count() >= self::MIN_GAMES && $key !== '-')
                ->map(fn (Collection $g, string $key) => [
                    'key' => $key,
                    ...$this->classifier->describe($key, $this->mostCommonTrait($g)),
                    'games' => $g->count(),
                    'share' => round($g->count() / $total, 4),
                    'avgPlacement' => round($g->avg('placement'), 2),
                    'top4Rate' => round($g->where('placement', '<=', 4)->count() / $g->count() * 100, 1),
                    'units' => $this->unitShares($g),
                ])
                ->sortByDesc('games')
                ->all());
        });
    }

    /**
     * How often each unit ends up on a board at all in this set (0..1): the
     * "normal" level of contest when we know nothing about the players.
     *
     * @return array<string, float>
     */
    public function unitBaseline(?int $set): array
    {
        if ($set === null) {
            return [];
        }

        return Cache::remember("unit-baseline:{$set}", now()->addMinutes(10), function () use ($set) {
            $boards = Participant::query()
                ->whereHas('match', fn ($q) => $q->againstPlayers()->where('set_number', $set))
                ->get(['units']);

            $counts = [];
            foreach ($boards as $board) {
                foreach (array_unique(array_column($board->units, 'character_id')) as $id) {
                    $counts[$id] = ($counts[$id] ?? 0) + 1;
                }
            }

            return array_map(fn (int $c) => $c / max(1, $boards->count()), $counts);
        });
    }

    /**
     * The main trait most often played with a group of boards.
     *
     * @param  Collection<int, Participant>  $boards
     */
    public function mostCommonTrait(Collection $boards): ?string
    {
        $trait = $boards
            ->map(fn (Participant $p) => $this->classifier->classify($p->traits, $p->units)['trait'])
            ->filter()
            ->countBy()
            ->sortDesc()
            ->keys()
            ->first();

        return is_string($trait) ? $trait : null;
    }

    /**
     * Share of boards in the group that contain each unit, most common first.
     *
     * @param  Collection<int, Participant>  $boards
     * @return list<array{id: string, name: string, cost: int, icon: ?string, share: float}>
     */
    public function unitShares(Collection $boards, float $minShare = 0.2): array
    {
        $counts = [];

        foreach ($boards as $board) {
            foreach (array_unique(array_column($board->units, 'character_id')) as $id) {
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }

        $rows = [];
        foreach ($counts as $id => $count) {
            $share = $count / max(1, $boards->count());

            if ($share >= $minShare) {
                $champion = $this->static->champion($id);
                $rows[] = ['id' => $id, 'name' => $champion['name'], 'cost' => $champion['cost'], 'icon' => $champion['icon'], 'share' => round($share, 2)];
            }
        }

        usort($rows, fn (array $a, array $b) => [$b['share'], $b['cost']] <=> [$a['share'], $a['cost']]);

        return $rows;
    }
}
