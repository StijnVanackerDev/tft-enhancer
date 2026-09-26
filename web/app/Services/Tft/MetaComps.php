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
 * @phpstan-type Comp array{key: string, label: string, icon: ?string, carryCost: int, levelling: ?string, games: int, share: float, avgPlacement: ?float, top4Rate: ?float, players: int, enterable: bool, units: list<array{id: string, name: string, cost: int, icon: ?string, share: float, copies: float, threeStarRate: float, items: float, role: string, needed: int}>}
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
            $units = $this->withRoles($units, $games, $definition->carries, $definition->levelling);
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
            $units = $this->withRoles($this->unitShares($g), $g, [(string) $key], null);
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
     * What each unit does in a comp, from our own boards of it:
     *  - "target": 3-starred in many boards (reroll comps), needs 9 copies;
     *  - "carry": usually holds items (carries and item tanks), needs the copies
     *    of its usual star level (2★ = 3 for 4/5-costs, which are rarely 3★);
     *  - "filler": played for its traits, doesn't decide whether a comp is open.
     * With too few games, the comp's named carries are carries (or 3★
     * targets in a reroll comp) and everything else is filler.
     *
     * @param  list<array{id: string, name: string, cost: int, icon: ?string, share: float}>  $units
     * @param  Collection<int, Participant>  $games
     * @param  list<string>  $carries
     * @return list<array{id: string, name: string, cost: int, icon: ?string, share: float, copies: float, threeStarRate: float, items: float, role: string, needed: int}>
     */
    private function withRoles(array $units, Collection $games, array $carries, ?string $levelling): array
    {
        $targetRate = (float) config('tft.roles.target_three_star_rate');
        $carryItems = (float) config('tft.roles.carry_min_items');
        $reroll = $levelling !== null && str_starts_with(strtolower($levelling), 'lvl');

        return array_map(function (array $unit) use ($games, $carries, $targetRate, $carryItems, $reroll) {
            $held = $games
                ->map(fn (Participant $g) => array_values(array_filter($g->units, fn (array $u) => $u['character_id'] === $unit['id'])))
                ->filter(fn (array $copies) => $copies !== []);

            $copies = $held->isEmpty() ? 0.0 : (float) $held->avg(fn (array $us) => array_sum(array_map(fn (array $u) => Participant::copiesForStars($u['tier']), $us)));
            $threeStar = $held->isEmpty() ? 0.0 : $held->filter(fn (array $us) => max(array_column($us, 'tier')) >= 3)->count() / $held->count();
            $items = $held->isEmpty() ? 0.0 : (float) $held->avg(fn (array $us) => array_sum(array_map(fn (array $u) => count($u['items']), $us)));

            if ($held->count() >= 5) {
                $role = match (true) {
                    $threeStar >= $targetRate => 'target',
                    $items >= $carryItems => 'carry',
                    default => 'filler',
                };
            } else {
                $isNamed = in_array($unit['id'], $carries, true);
                $role = match (true) {
                    $isNamed && $reroll && $unit['cost'] <= 3 => 'target',
                    $isNamed => 'carry',
                    default => 'filler',
                };
            }

            return [
                ...$unit,
                'copies' => round($copies, 1),
                'threeStarRate' => round($threeStar, 2),
                'items' => round($items, 1),
                'role' => $role,
                'needed' => match ($role) {
                    'target' => 9,
                    // 2★ (3 copies) only when the comp nearly always 2-stars it;
                    // many 5-costs and item tanks are usually played at 1★.
                    'carry' => $held->isEmpty() || $copies >= 2.5 ? 3 : 1,
                    default => 0,
                },
            ];
        }, $units);
    }

    /**
     * Boards per rank we found their match through, highest rank first.
     *
     * @return array<string, int>
     */
    public function boardsPerTier(?int $set): array
    {
        if ($set === null) {
            return [];
        }

        $order = ['CHALLENGER', 'GRANDMASTER', 'MASTER', 'DIAMOND', 'EMERALD', 'PLATINUM', 'GOLD', 'SILVER', 'BRONZE', 'IRON'];

        $counts = Participant::query()
            ->join('tft_matches', 'tft_matches.id', '=', 'participants.tft_match_id')
            ->where('tft_matches.set_number', $set)
            ->where(fn ($q) => $q->whereNull('tft_matches.game_type')->orWhere('tft_matches.game_type', '!=', TftMatch::GAME_TYPE_BOTS))
            ->selectRaw('tft_matches.sample_tier as tier, count(*) as boards')
            ->groupBy('tft_matches.sample_tier')
            ->pluck('boards', 'tier')
            ->all();

        $sorted = [];
        foreach ([...$order, ''] as $tier) {
            if (isset($counts[$tier]) && (int) $counts[$tier] > 0) {
                $sorted[$tier === '' ? 'UNKNOWN' : $tier] = (int) $counts[$tier];
            }
        }

        return $sorted;
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
     * Average pool copies of each unit on the boards that have it, e.g. 1.4
     * for a unit usually played 1★, close to 9 for a reroll carry.
     *
     * @return array<string, float>
     */
    public function unitCopies(?int $set): array
    {
        if ($set === null) {
            return [];
        }

        return Cache::remember("unit-copies:{$set}", now()->addMinutes(10), function () use ($set) {
            $boards = Participant::query()
                ->whereHas('match', fn ($q) => $q->againstPlayers()->where('set_number', $set))
                ->get(['units']);

            $copies = [];
            $boardsWith = [];
            foreach ($boards as $board) {
                foreach ($board->copiesByUnit() as $id => $count) {
                    $copies[$id] = ($copies[$id] ?? 0) + $count;
                    $boardsWith[$id] = ($boardsWith[$id] ?? 0) + 1;
                }
            }

            $average = [];
            foreach ($copies as $id => $total) {
                $average[$id] = $total / $boardsWith[$id];
            }

            return $average;
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
