<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Star } from '@lucide/vue';
import { cn } from '@/lib/utils';
import { costBorderClass } from '@/lib/tft';
import type { MetaComp } from '@/types';

const roleLabel = {
    target: '3★ target',
    carry: 'carry / item holder',
    filler: 'filler',
} as const;

defineProps<{
    set: number | null;
    comps: MetaComp[];
    totalGames: number;
    coveredGames: number;
    // Boards per rank their match was found through.
    tiers: Record<string, number>;
    // Start of the period that counts as the current meta (last comp data refresh).
    windowStart: string | null;
}>();

function tierName(tier: string): string {
    return tier.charAt(0) + tier.slice(1).toLowerCase();
}
</script>

<template>
    <Head title="Comps" />

    <div class="flex flex-col gap-6">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">
                Comps<template v-if="set"> · Set {{ set }}</template>
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Placement stats come from {{ totalGames }} boards from high-elo
                games and searched players' lobbies that match one of these
                comps ({{
                    totalGames
                        ? Math.round((coveredGames / totalGames) * 100)
                        : 0
                }}% of all boards). ★ marks 3★ targets; faded units are fillers
                played for their traits. Hover a unit for details.
            </p>
            <p
                v-if="Object.keys(tiers).length"
                class="mt-1 text-xs text-muted-foreground"
            >
                <template v-if="windowStart">
                    Games since
                    {{
                        new Date(windowStart).toLocaleDateString('en-GB', {
                            day: 'numeric',
                            month: 'short',
                        })
                    }}
                    (last comp data refresh).
                </template>
                Boards by rank:
                <template v-for="(boards, tier, i) in tiers" :key="tier">
                    <template v-if="i > 0"> · </template>
                    {{ tierName(String(tier)) }} {{ boards }}
                </template>
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
                                <div>
                                    <div class="font-medium">
                                        {{ comp.label }}
                                    </div>
                                    <div
                                        v-if="comp.levelling"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ comp.levelling }}
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="py-2 pr-3">
                            <div class="flex flex-wrap gap-1">
                                <span
                                    v-for="unit in comp.units"
                                    :key="unit.id"
                                    class="relative"
                                    :title="`${unit.name}: ${roleLabel[unit.role]} · in ${Math.round(unit.share * 100)}% of boards · ${Math.round(unit.threeStarRate * 100)}% 3★ · ${unit.items} items`"
                                >
                                    <img
                                        :src="unit.icon ?? undefined"
                                        :alt="unit.name"
                                        loading="lazy"
                                        :class="
                                            cn(
                                                'size-7 rounded border-2 object-cover',
                                                costBorderClass(unit.cost),
                                                unit.role === 'filler' &&
                                                    'opacity-40',
                                            )
                                        "
                                    />
                                    <Star
                                        v-if="unit.role === 'target'"
                                        class="absolute -top-1 -right-1 size-3 fill-cost-5 text-cost-5"
                                    />
                                </span>
                            </div>
                        </td>
                        <td class="py-2 text-right tabular-nums">
                            {{ comp.games }}
                        </td>
                        <td class="py-2 text-right tabular-nums">
                            {{ comp.avgPlacement?.toFixed(2) ?? '–' }}
                        </td>
                        <td class="py-2 text-right tabular-nums">
                            {{
                                comp.top4Rate === null
                                    ? '–'
                                    : `${comp.top4Rate}%`
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
