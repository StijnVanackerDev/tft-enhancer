<?php

namespace App\Services\Tft;

use App\Models\CompDefinition;
use App\Models\Participant;
use App\Models\TftMatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Comp statistics across every stored board of a set: how often each comp is
 * played, how it places, and which units it usually runs. Built from our own
 * database (searched players' lobbies and `tft:crawl-meta`), aggregated over
 * all players, so no individual player's data is exposed.
 *
 * @phpstan-type Comp array{key: string, label: string, icon: ?string, carryCost: int, levelling: ?string, games: int, share: float, avgPlacement: ?float, top4Rate: ?float, players: int, enterable: bool, units: list<array{id: string, name: string, cost: int, icon: ?string, share: float}>}
 */
class MetaComps
{
    /** Comps seen fewer times than this are too noisy to show. */
    public const MIN_GAMES = 3;

    /** A unit counts as "core" for a comp when at least this share of its boards run it. */
    public const CORE_UNIT_SHARE = 0.5;

    /**
     * An "enterable" comp is a real, repeatable line rather than a situational
     * board: it has at least this many core units...
     */
    public const ENTERABLE_MIN_CORE_UNITS = 4;

    /** ...makes up at least this share of all boards... */
    public const ENTERABLE_MIN_SHARE = 0.01;

    /** ...and is played by this share of all boards' worth of different players (min 3). */
    public const ENTERABLE_MIN_PLAYERS_SHARE = 0.005;

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
     * All comps of a set with our own statistics. With imported definitions
     * every definition is listed (even without games yet); otherwise comps
     * are derived from the boards themselves.
     *
     * @return list<Comp>
     */
    public function forSet(?int $set): array
    {
        if ($set === null) {
            return [];
        }

        return Cache::remember("meta-comps:{$set}", now()->addMinutes(10), function () use ($set) {
            $boards = Participant::query()
                ->whereHas('match', fn ($q) => $q->againstPlayers()->where('set_number', $set))
                ->get(['puuid', 'placement', 'traits', 'units']);

            $groups = $boards
                ->toBase()
                ->groupBy(fn (Participant $p) => $this->classifier->classify($p->traits, $p->units)['key'])
                ->reject(fn (Collection $g, string $key) => ! $this->classifier->isComp($key));

            $definitions = $this->classifier->definitions()->where('set_number', $set);

            $comps = $definitions->isNotEmpty()
                ? $this->fromDefinitions($definitions, $groups)
                : $this->fromBoards($groups, $boards->count());

            usort($comps, fn (array $a, array $b) => [$b['games'], $a['label']] <=> [$a['games'], $b['label']]);

            return $comps;
        });
    }

    /**
     * @param  Collection<int, CompDefinition>  $definitions
     * @param  Collection<array-key, Collection<int, Participant>>  $groups
     * @return list<Comp>
     */
    private function fromDefinitions(Collection $definitions, Collection $groups): array
    {
        $total = max(1, $groups->sum(fn (Collection $g) => $g->count()));
        $comps = [];

        foreach ($definitions as $definition) {
            $key = $definition->compKey();
            /** @var Collection<int, Participant> $games */
            $games = $groups->get($key, collect());
            $enough = $games->count() >= self::MIN_GAMES;
            $measured = collect($this->unitShares($games, 0))->keyBy('id');

            // The definition's units are the comp; with enough games of our
            // own we show how often each one is actually played.
            $units = array_map(function (string $id) use ($measured, $games) {
                $champion = $this->static->champion($id);

                return [
                    'id' => $id,
                    'name' => $champion['name'],
                    'cost' => $champion['cost'],
                    'icon' => $champion['icon'],
                    'share' => $games->count() >= 5 ? (float) ($measured->get($id)['share'] ?? 0) : 1.0,
                ];
            }, $definition->units);
            usort($units, fn (array $a, array $b) => [$b['share'], $b['cost']] <=> [$a['share'], $a['cost']]);

            $comps[] = [
                'key' => $key,
                ...$this->classifier->describe($key),
                'games' => $games->count(),
                'share' => round($games->count() / $total, 4),
                'avgPlacement' => $enough ? round($games->avg('placement'), 2) : null,
                'top4Rate' => $enough ? round($games->where('placement', '<=', 4)->count() / $games->count() * 100, 1) : null,
                'players' => $games->pluck('puuid')->unique()->count(),
                // Imported comps are known, repeatable lines.
                'enterable' => true,
                'units' => $units,
            ];
        }

        return $comps;
    }

    /**
     * Fallback without definitions: comps named after their carry.
     *
     * @param  Collection<array-key, Collection<int, Participant>>  $groups
     * @return list<Comp>
     */
    private function fromBoards(Collection $groups, int $boards): array
    {
        $groups = $groups->filter(fn (Collection $g) => $g->count() >= self::MIN_GAMES);
        $total = max(1, $groups->sum(fn (Collection $g) => $g->count()));
        $minGames = max(self::MIN_GAMES, $boards * self::ENTERABLE_MIN_SHARE);
        $minPlayers = max(3, $boards * self::ENTERABLE_MIN_PLAYERS_SHARE);
        $comps = [];

        foreach ($groups as $key => $g) {
            $units = $this->unitShares($g);
            $core = count(array_filter($units, fn (array $u) => $u['share'] >= self::CORE_UNIT_SHARE));
            $players = $g->pluck('puuid')->unique()->count();

            $comps[] = [
                'key' => (string) $key,
                ...$this->classifier->describe((string) $key, $this->mostCommonTrait($g)),
                'games' => $g->count(),
                'share' => round($g->count() / $total, 4),
                'avgPlacement' => round($g->avg('placement'), 2),
                'top4Rate' => round($g->where('placement', '<=', 4)->count() / $g->count() * 100, 1),
                'players' => $players,
                'enterable' => $core >= self::ENTERABLE_MIN_CORE_UNITS
                    && $g->count() >= $minGames
                    && $players >= $minPlayers,
                'units' => $units,
            ];
        }

        return $comps;
    }

    /**
     * All stored boards of a set (games vs bots excluded).
     */
    public function boardCount(?int $set): int
    {
        return $set === null ? 0 : Participant::query()
            ->whereHas('match', fn ($q) => $q->againstPlayers()->where('set_number', $set))
            ->count();
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
