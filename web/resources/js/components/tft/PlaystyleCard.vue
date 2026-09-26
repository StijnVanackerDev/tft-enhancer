<script setup lang="ts">
import { computed } from 'vue';
import { percent } from '@/lib/tft';
import type { Playstyle } from '@/types';

const props = defineProps<{
    playstyle: Playstyle;
    title?: string;
}>();

const tempo = computed(() => {
    const t = props.playstyle.levelTempo;
    if (t === null) {
        return '–';
    }

    return `${t > 0 ? '+' : ''}${t.toFixed(1)} lvl`;
});

const metrics = computed(() => [
    {
        label: 'Avg level',
        value: props.playstyle.avgLevel ?? '–',
        hint: 'Level at the end of the game.',
    },
    {
        label: 'Level 9+',
        value: percent(props.playstyle.level9Rate),
        hint: 'Share of games that ended at level 9 or 10.',
    },
    {
        label: 'Leveling',
        value: tempo.value,
        hint: 'Compared to other players knocked out in the same stage. Positive = levels faster than usual.',
    },
    {
        label: 'Reroll',
        value: percent(props.playstyle.rerollRate),
        hint: 'Games carried by a 3★ unit costing 1–3 gold.',
    },
    {
        label: '4/5-cost carry',
        value: percent(props.playstyle.highCostRate),
        hint: 'Games carried by a 4- or 5-cost unit.',
    },
    {
        label: 'Flexibility',
        value:
            props.playstyle.flexibility === null
                ? '–'
                : `${props.playstyle.flexibility}/100`,
        hint: '0 = always the same comp, 100 = a different comp every game.',
    },
]);
</script>

<template>
    <section
        class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
    >
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <h2 class="text-sm font-semibold">{{ title ?? 'Playstyle' }}</h2>
            <span
                v-for="tag in playstyle.tags"
                :key="tag.label"
                :title="tag.description"
                class="rounded bg-cost-5/20 px-1.5 py-0.5 text-[11px] font-medium text-foreground"
            >
                {{ tag.label }}
            </span>
        </div>

        <p v-if="playstyle.games === 0" class="text-sm text-muted-foreground">
            No games in the current set yet.
        </p>

        <template v-else>
            <dl class="grid grid-cols-3 gap-x-3 gap-y-2">
                <div v-for="metric in metrics" :key="metric.label">
                    <dt
                        class="text-[11px] text-muted-foreground"
                        :title="metric.hint"
                    >
                        {{ metric.label }}
                    </dt>
                    <dd class="text-sm font-semibold tabular-nums">
                        {{ metric.value }}
                    </dd>
                </div>
            </dl>

            <div v-if="playstyle.comps.length" class="mt-3">
                <div class="mb-1 text-[11px] text-muted-foreground">
                    Most played comps
                </div>
                <ul class="flex flex-col gap-1">
                    <li
                        v-for="comp in playstyle.comps"
                        :key="comp.key"
                        class="flex items-center gap-2 text-sm"
                    >
                        <img
                            v-if="comp.icon"
                            :src="comp.icon"
                            :alt="comp.label"
                            class="size-5 rounded object-cover"
                            loading="lazy"
                        />
                        <span class="flex-1 truncate">{{ comp.label }}</span>
                        <span
                            class="text-xs text-muted-foreground tabular-nums"
                        >
                            {{ comp.games }}× · avg
                            {{ comp.avgPlacement.toFixed(1) }}
                        </span>
                    </li>
                </ul>
            </div>
        </template>
    </section>
</template>
