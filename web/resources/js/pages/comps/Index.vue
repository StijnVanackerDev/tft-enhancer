<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { cn } from '@/lib/utils';
import { costBorderClass } from '@/lib/tft';
import type { MetaComp } from '@/types';

defineProps<{
    set: number | null;
    comps: MetaComp[];
    totalGames: number;
}>();
</script>

<template>
    <Head title="Comps" />

    <div class="flex flex-col gap-6">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">
                Comps<template v-if="set"> · Set {{ set }}</template>
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Based on {{ totalGames }} boards from high-elo games and
                searched players' lobbies. A comp is named after its main trait
                and its carry (the unit holding the most items).
            </p>
        </header>

        <p v-if="comps.length === 0" class="text-sm text-muted-foreground">
            No comp data yet.
        </p>

        <div class="overflow-x-auto">
            <table v-if="comps.length" class="w-full min-w-[640px] text-sm">
                <thead>
                    <tr class="text-left text-xs text-muted-foreground">
                        <th class="pb-2 font-medium">Comp</th>
                        <th class="pb-2 font-medium">Core units</th>
                        <th class="pb-2 text-right font-medium">Games</th>
                        <th class="pb-2 text-right font-medium">Avg place</th>
                        <th class="pb-2 text-right font-medium">Top 4</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="comp in comps"
                        :key="comp.key"
                        class="border-t border-sidebar-border/50"
                    >
                        <td class="py-2 pr-3">
                            <div class="flex items-center gap-2">
                                <img
                                    v-if="comp.icon"
                                    :src="comp.icon"
                                    :alt="comp.label"
                                    loading="lazy"
                                    :class="
                                        cn(
                                            'size-8 rounded border-2 object-cover',
                                            costBorderClass(comp.carryCost),
                                        )
                                    "
                                />
                                <span class="font-medium">{{
                                    comp.label
                                }}</span>
                            </div>
                        </td>
                        <td class="py-2 pr-3">
                            <div class="flex flex-wrap gap-1">
                                <img
                                    v-for="unit in comp.units.filter(
                                        (u) => u.share >= 0.5,
                                    )"
                                    :key="unit.id"
                                    :src="unit.icon ?? undefined"
                                    :alt="unit.name"
                                    :title="`${unit.name} (${Math.round(unit.share * 100)}% of boards)`"
                                    loading="lazy"
                                    :class="
                                        cn(
                                            'size-7 rounded border-2 object-cover',
                                            costBorderClass(unit.cost),
                                        )
                                    "
                                />
                            </div>
                        </td>
                        <td class="py-2 text-right tabular-nums">
                            {{ comp.games }}
                        </td>
                        <td class="py-2 text-right tabular-nums">
                            {{ comp.avgPlacement.toFixed(2) }}
                        </td>
                        <td class="py-2 text-right tabular-nums">
                            {{ comp.top4Rate }}%
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
