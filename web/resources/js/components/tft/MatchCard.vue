<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import { Users } from '@lucide/vue';
import UnitIcon from '@/components/tft/UnitIcon.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import {
    formatDuration,
    ordinal,
    placementClass,
    roundToStage,
    timeAgo,
    traitStyleClass,
} from '@/lib/tft';
import { store as analyseLobby, show as showLobby } from '@/routes/lobby';
import type { Game } from '@/types';

defineProps<{
    game: Game;
    // Set when lobby analysis is enabled: shows the "Analyse lobby" button.
    analysePlayerId?: number;
}>();
</script>

<template>
    <article
        class="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 p-3 sm:flex-row sm:items-center dark:border-sidebar-border"
    >
        <div class="flex shrink-0 items-center gap-3 sm:w-40">
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
                <Button
                    v-if="analysePlayerId && game.lobbyAnalysisId"
                    as-child
                    variant="outline"
                    size="sm"
                    class="mt-1 h-6 px-2 text-[11px]"
                >
                    <Link :href="showLobby(game.lobbyAnalysisId)">
                        <Users class="size-3" />
                        View analysis
                    </Link>
                </Button>
                <Form
                    v-else-if="analysePlayerId"
                    v-bind="analyseLobby.form(analysePlayerId)"
                    v-slot="{ processing }"
                    class="mt-1"
                >
                    <input type="hidden" name="source" value="match" />
                    <input type="hidden" name="match_id" :value="game.id" />
                    <Button
                        type="submit"
                        variant="outline"
                        size="sm"
                        class="h-6 px-2 text-[11px]"
                        :disabled="processing"
                    >
                        <Spinner v-if="processing" />
                        <Users v-else class="size-3" />
                        Analyse lobby
                    </Button>
                </Form>
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
