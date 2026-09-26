<script setup lang="ts">
import { Deferred, Form, Head, usePoll } from '@inertiajs/vue3';
import { RefreshCw } from '@lucide/vue';
import { computed, watch } from 'vue';
import ActiveGamePanel from '@/components/tft/ActiveGamePanel.vue';
import MatchCard from '@/components/tft/MatchCard.vue';
import PlaystyleCard from '@/components/tft/PlaystyleCard.vue';
import StatTable from '@/components/tft/StatTable.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { ordinal, rankLabel, timeAgo } from '@/lib/tft';
import { refresh } from '@/routes/players';
import type {
    ActiveGame,
    Game,
    Player,
    Playstyle,
    StatRow,
    Summary,
} from '@/types';

const props = defineProps<{
    player: Player;
    summary: Summary;
    set: number | null;
    units: StatRow[];
    traits: StatRow[];
    playstyle: Playstyle;
    matches: Game[];
    features: { lobbyAnalysis: boolean };
    activeGame?: ActiveGame | null;
}>();

// While a sync runs in the background, reload the page data every 2 seconds.
// The live game check is deferred and doesn't need to be repeated.
const { start, stop } = usePoll(
    2000,
    {
        only: [
            'player',
            'summary',
            'set',
            'units',
            'traits',
            'playstyle',
            'matches',
        ],
    },
    { autoStart: false },
);
watch(
    () => props.player.isSyncing,
    (syncing) => (syncing ? start() : stop()),
    { immediate: true },
);

const rankText = computed(() => rankLabel(props.player.rank));

const maxPlacementCount = computed(() =>
    Math.max(1, ...props.summary.placements),
);

const stats = computed(() => [
    { label: 'Games', value: props.summary.games },
    { label: 'Avg placement', value: props.summary.avgPlacement ?? '–' },
    {
        label: 'Top 4',
        value:
            props.summary.top4Rate === null
                ? '–'
                : `${props.summary.top4Rate}%`,
    },
    {
        label: 'Wins',
        value:
            props.summary.winRate === null ? '–' : `${props.summary.winRate}%`,
    },
]);
</script>

<template>
    <Head :title="`${player.gameName}#${player.tagLine}`" />

    <div class="flex flex-col gap-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    {{ player.gameName
                    }}<span class="text-muted-foreground"
                        >#{{ player.tagLine }}</span
                    >
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ player.platformLabel }} · {{ rankText }}
                    <template v-if="player.rank">
                        · {{ player.rank.wins }}W {{ player.rank.losses }}L
                    </template>
                </p>
            </div>

            <div class="flex items-center gap-3 text-xs text-muted-foreground">
                <span v-if="player.isSyncing">Fetching matches…</span>
                <span v-else-if="player.syncedAt">
                    Updated {{ timeAgo(player.syncedAt) }}
                </span>
                <Form
                    v-bind="refresh.form(player.id)"
                    v-slot="{ processing }"
                    :options="{ preserveScroll: true }"
                >
                    <Button
                        type="submit"
                        variant="outline"
                        size="sm"
                        :disabled="processing || player.isSyncing"
                    >
                        <Spinner v-if="processing || player.isSyncing" />
                        <RefreshCw v-else />
                        Update
                    </Button>
                </Form>
            </div>
        </header>

        <div
            v-if="player.syncError"
            class="rounded-lg border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm"
        >
            Couldn't update this player: {{ player.syncError }}
        </div>

        <section class="grid gap-4 md:grid-cols-[2fr_1fr]">
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div
                    v-for="stat in stats"
                    :key="stat.label"
                    class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                >
                    <div class="text-xs text-muted-foreground">
                        {{ stat.label }}
                    </div>
                    <div class="mt-1 text-2xl font-semibold tabular-nums">
                        {{ stat.value }}
                    </div>
                </div>
            </div>

            <div
                class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
            >
                <div class="text-xs text-muted-foreground">Placements</div>
                <div class="mt-2 flex h-16 items-end gap-1.5">
                    <div
                        v-for="(count, i) in summary.placements"
                        :key="i"
                        class="flex flex-1 flex-col items-center gap-1"
                        :title="`${ordinal(i + 1)}: ${count}×`"
                    >
                        <div
                            class="w-full rounded-sm"
                            :class="
                                i < 4 ? 'bg-cost-3' : 'bg-muted-foreground/40'
                            "
                            :style="{
                                height: `${(count / maxPlacementCount) * 48}px`,
                                minHeight: count ? '3px' : '0',
                            }"
                        />
                        <span class="text-[10px] text-muted-foreground">{{
                            i + 1
                        }}</span>
                    </div>
                </div>
            </div>
        </section>

        <div class="grid gap-6 lg:grid-cols-[1fr_320px]">
            <section class="flex flex-col gap-3">
                <h2 class="text-sm font-semibold">Recent matches</h2>
                <p
                    v-if="matches.length === 0 && player.isSyncing"
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <Spinner /> Loading matches from Riot…
                </p>
                <p
                    v-else-if="matches.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    No recent TFT matches found.
                </p>
                <MatchCard
                    v-for="game in matches"
                    :key="game.id"
                    :game="game"
                    :analyse-player-id="
                        features.lobbyAnalysis ? player.id : undefined
                    "
                />
            </section>

            <aside class="flex flex-col gap-6">
                <Deferred v-if="features.lobbyAnalysis" data="activeGame">
                    <template #fallback>
                        <div
                            class="flex items-center gap-2 text-xs text-muted-foreground"
                        >
                            <Spinner /> Checking for a live game…
                        </div>
                    </template>
                    <ActiveGamePanel
                        v-if="activeGame"
                        :player-id="player.id"
                        :game="activeGame"
                    />
                </Deferred>
                <PlaystyleCard
                    :playstyle="playstyle"
                    :title="set ? `Playstyle (Set ${set})` : 'Playstyle'"
                />
                <StatTable
                    :title="set ? `Your units (Set ${set})` : 'Your units'"
                    :rows="units"
                    square
                />
                <StatTable
                    :title="set ? `Your traits (Set ${set})` : 'Your traits'"
                    :rows="traits"
                />
            </aside>
        </div>
    </div>
</template>
