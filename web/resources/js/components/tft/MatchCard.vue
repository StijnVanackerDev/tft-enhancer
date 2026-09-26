<script setup lang="ts">
import UnitIcon from '@/components/tft/UnitIcon.vue';
import { cn } from '@/lib/utils';
import {
    formatDuration,
    ordinal,
    placementClass,
    roundToStage,
    timeAgo,
    traitStyleClass,
} from '@/lib/tft';
import type { Game } from '@/types';

defineProps<{
    game: Game;
}>();
</script>

<template>
    <article
        class="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 p-3 sm:flex-row sm:items-center dark:border-sidebar-border"
    >
        <div class="flex shrink-0 items-center gap-3 sm:w-36">
            <div
                :class="
                    cn(
                        'flex size-11 items-center justify-center rounded-lg text-lg font-semibold',
                        placementClass(game.placement),
                    )
                "
            >
                {{ ordinal(game.placement) }}
            </div>
            <div class="text-xs leading-5 text-muted-foreground">
                <div class="font-medium text-foreground">{{ game.queue }}</div>
                <div>{{ timeAgo(game.playedAt) }}</div>
                <div>
                    {{ formatDuration(game.duration) }} · lvl {{ game.level }} ·
                    {{ roundToStage(game.lastRound) }}
                </div>
            </div>
        </div>

        <div class="flex min-w-0 flex-1 flex-col gap-2">
            <div class="flex flex-wrap gap-1">
                <span
                    v-for="trait in game.traits"
                    :key="trait.id"
                    :class="
                        cn(
                            'inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-medium',
                            traitStyleClass(trait.style),
                        )
                    "
                    :title="`${trait.units} ${trait.name}`"
                >
                    {{ trait.units }} {{ trait.name }}
                </span>
            </div>
            <div class="flex flex-wrap gap-1">
                <UnitIcon
                    v-for="(unit, i) in game.units"
                    :key="i"
                    :unit="unit"
                />
            </div>
        </div>
    </article>
</template>
