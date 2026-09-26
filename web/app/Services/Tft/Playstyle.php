<?php

namespace App\Services\Tft;

use App\Models\Participant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Describes how someone plays, from the final state of their past games.
 *
 * Riot only gives the end-of-game state (level, round, board), so leveling
 * speed is estimated by comparing a player's level with the typical level of
 * players knocked out in the same stage: someone already level 9 when they
 * die in stage 4 levels aggressively, someone level 7 in stage 5 slow-rolls.
 */
class Playstyle
{
    /** Typical level per stage, used until we have enough games of a set. */
    private const DEFAULT_LEVEL_BY_STAGE = [1 => 3.0, 2 => 5.0, 3 => 6.5, 4 => 7.6, 5 => 8.3, 6 => 8.8, 7 => 9.1];

    /** Past games counted with this weight per step back in time. */
    public const RECENCY_DECAY = 0.85;

    public function __construct(
        private readonly CompClassifier $classifier,
        private readonly StaticData $static,
    ) {}

    public static function stageOf(int $round): int
    {
        return $round <= 3 ? 1 : intdiv($round - 4, 7) + 2;
    }

    /**
     * @param  Collection<int, Participant>  $games  Most recent first.
     * @param  array<int, float>|null  $levelByStage
     * @return array{games: int, avgPlacement: float|null, top4Rate: float|null, avgLevel: float|null, level9Rate: float|null, levelTempo: float|null, rerollRate: float|null, highCostRate: float|null, flexibility: int|null, tags: list<array{label: string, description: string}>, comps: list<array{key: string, label: string, icon: ?string, games: int, avgPlacement: float}>}
     */
    public function profile(Collection $games, ?array $levelByStage = null): array
    {
        $count = $games->count();

        if ($count === 0) {
            return [
                'games' => 0, 'avgPlacement' => null, 'top4Rate' => null, 'avgLevel' => null, 'level9Rate' => null,
                'levelTempo' => null, 'rerollRate' => null, 'highCostRate' => null, 'flexibility' => null,
                'tags' => [], 'comps' => [],
            ];
        }

        $levelByStage ??= self::DEFAULT_LEVEL_BY_STAGE;

        $rows = $games->map(function (Participant $game) use ($levelByStage) {
            $comp = $this->classifier->classify($game->traits, $game->units);
            $carryCost = $comp['carry'] ? $this->static->champion($comp['carry'])['cost'] : 0;
            $stage = self::stageOf($game->last_round);

            return [
                'placement' => $game->placement,
                'level' => $game->level,
                'levelDiff' => $game->level - ($levelByStage[$stage] ?? self::DEFAULT_LEVEL_BY_STAGE[min($stage, 7)]),
                'reroll' => $carryCost > 0 && $carryCost <= 3 && $comp['carryStars'] >= 3,
                'highCost' => $carryCost >= 4,
                'key' => $comp['key'],
                'trait' => $comp['trait'],
            ];
        });

        $comps = $rows->groupBy('key');
        $topShare = $comps->max(fn (Collection $g) => $g->count()) / $count;

        $profile = [
            'games' => $count,
            'avgPlacement' => round($rows->avg('placement'), 2),
            'top4Rate' => round($rows->where('placement', '<=', 4)->count() / $count * 100, 1),
            'avgLevel' => round($rows->avg('level'), 1),
            'level9Rate' => round($rows->where('level', '>=', 9)->count() / $count, 2),
            'levelTempo' => round($rows->avg('levelDiff'), 2),
            'rerollRate' => round($rows->where('reroll', true)->count() / $count, 2),
            'highCostRate' => round($rows->where('highCost', true)->count() / $count, 2),
            // 0 = always the same comp, 100 = a different comp every game.
            'flexibility' => $count > 1 ? (int) round(($comps->count() - 1) / ($count - 1) * 100) : null,
            'comps' => $this->topComps($comps),
        ];

        $profile['tags'] = $this->tags($profile, $topShare);

        return $profile;
    }

    /**
     * @param  Collection<array-key, Collection<int, array{placement: int, level: int, levelDiff: float, reroll: bool, highCost: bool, key: string, trait: ?string}>>  $comps  Game rows grouped by comp key.
     * @return list<array{key: string, label: string, icon: ?string, games: int, avgPlacement: float}>
     */
    private function topComps(Collection $comps): array
    {
        $rows = [];

        foreach ($comps->sortByDesc(fn (Collection $g) => $g->count())->take(3) as $key => $group) {
            $trait = $group->pluck('trait')->filter()->countBy()->sortDesc()->keys()->first();
            $comp = $this->classifier->describe((string) $key, is_string($trait) ? $trait : null);
            $rows[] = [
                'key' => (string) $key,
                'label' => $comp['label'],
                'icon' => $comp['icon'],
                'games' => $group->count(),
                'avgPlacement' => round((float) $group->avg('placement'), 2),
            ];
        }

        return $rows;
    }

    /**
     * Average level of players eliminated in each stage, for one set.
     *
     * @return array<int, float>
     */
    public function levelByStage(?int $set): array
    {
        if ($set === null) {
            return self::DEFAULT_LEVEL_BY_STAGE;
        }

        return Cache::remember("level-by-stage:{$set}", now()->addMinutes(30), function () use ($set) {
            $levels = self::DEFAULT_LEVEL_BY_STAGE;

            Participant::query()
                ->whereHas('match', fn ($q) => $q->againstPlayers()->where('set_number', $set))
                ->get(['level', 'last_round'])
                ->groupBy(fn (Participant $p) => self::stageOf($p->last_round))
                ->each(function (Collection $group, int $stage) use (&$levels) {
                    // Too few samples: keep the default for that stage.
                    if ($group->count() >= 20) {
                        $levels[$stage] = round($group->avg('level'), 2);
                    }
                });

            return $levels;
        });
    }

    /**
     * @param  array<string, mixed>  $p
     * @return list<array{label: string, description: string}>
     */
    private function tags(array $p, float $topShare): array
    {
        if ($p['games'] < 3) {
            return [];
        }

        $tags = [];

        if ($p['rerollRate'] >= 0.4) {
            $tags[] = ['label' => 'Reroll', 'description' => 'Often carries with a 3★ unit costing 1–3 gold.'];
        } elseif ($p['level9Rate'] >= 0.4 && $p['levelTempo'] >= 0) {
            $tags[] = ['label' => 'Fast 9', 'description' => 'Reaches level 9 in many games and plays expensive boards.'];
        } elseif ($p['highCostRate'] >= 0.5) {
            $tags[] = ['label' => 'Fast 8', 'description' => 'Usually carries with 4- or 5-cost units.'];
        }

        if ($p['levelTempo'] >= 0.35) {
            $tags[] = ['label' => 'Tempo', 'description' => 'Is usually a level ahead of other players at the same stage.'];
        } elseif ($p['levelTempo'] <= -0.35) {
            $tags[] = ['label' => 'Slow roll', 'description' => 'Usually a level behind other players at the same stage.'];
        }

        if ($p['games'] >= 4 && $topShare >= 0.5) {
            $tags[] = ['label' => 'One-trick', 'description' => 'Plays the same comp in at least half of their games.'];
        } elseif ($p['flexibility'] !== null && $p['flexibility'] >= 75) {
            $tags[] = ['label' => 'Flexible', 'description' => 'Plays a different comp almost every game.'];
        }

        return $tags;
    }
}
