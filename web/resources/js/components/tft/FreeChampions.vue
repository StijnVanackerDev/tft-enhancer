<script setup lang="ts">
import { cn } from '@/lib/utils';
import { costBorderClass } from '@/lib/tft';
import type { FreeChampion } from '@/types';

defineProps<{
    champions: FreeChampion[];
}>();

// Share of a champion's pool, as a CSS width (capped at 100%).
function poolWidth(copies: number, poolSize: number): string {
    return `${Math.min(100, (copies / poolSize) * 100)}%`;
}
</script>

<template>
    <section
        class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
    >
        <h2 class="text-sm font-semibold">Likely free champions</h2>
        <p class="mb-3 text-xs text-muted-foreground">
            Key champions of proven comps that the opponents are expected to
            take less of than usual. The grey mark is the usual level.
        </p>
        <p v-if="champions.length === 0" class="text-sm text-muted-foreground">
            Nothing is clearly less contested than usual in this lobby.
        </p>
        <ul class="flex flex-col gap-1.5">
            <li
                v-for="champ in champions"
                :key="champ.id"
                class="flex items-center gap-2 text-sm"
                :title="`${champ.expectedCopies} of ${champ.poolSize} copies expected to be taken (usually ${champ.usualCopies}).\nKey unit in: ${champ.keyIn.join(', ')}`"
            >
                <img
                    v-if="champ.icon"
                    :src="champ.icon"
                    :alt="champ.name"
                    loading="lazy"
                    :class="
                        cn(
                            'size-7 rounded border-2 object-cover',
                            costBorderClass(champ.cost),
                        )
                    "
                />
                <div class="w-28 min-w-0">
                    <div class="truncate">{{ champ.name }}</div>
                    <div class="truncate text-[11px] text-muted-foreground">
                        {{ champ.keyIn[0] }}
                    </div>
                </div>
                <div class="relative h-2 flex-1 rounded-full bg-muted">
                    <div
                        class="h-full rounded-full bg-cost-2/70"
                        :style="{
                            width: poolWidth(
                                champ.expectedCopies,
                                champ.poolSize,
                            ),
                        }"
                    />
                    <div
                        class="absolute -top-0.5 h-3 w-0.5 bg-foreground/60"
                        :style="{
                            left: poolWidth(champ.usualCopies, champ.poolSize),
                        }"
                    />
                </div>
                <span
                    class="w-20 text-right text-xs text-muted-foreground tabular-nums"
                >
                    {{ Math.round(champ.ratio * 100) }}% of usual
                </span>
            </li>
        </ul>
    </section>
</template>
