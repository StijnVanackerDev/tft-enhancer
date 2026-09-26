<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import FreeChampions from '@/components/tft/FreeChampions.vue';
import OpenCompCard from '@/components/tft/OpenCompCard.vue';
import ShowMoreButton from '@/components/tft/ShowMoreButton.vue';
import PlaystyleCard from '@/components/tft/PlaystyleCard.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { costBorderClass, ordinal, placementClass, rankLabel } from '@/lib/tft';
import { show as showPlayer } from '@/routes/players';
import type { LobbyAnalysis } from '@/types';

const props = defineProps<{
    analysis: LobbyAnalysis;
}>();

const finished = computed(() =>
    ['done', 'failed'].includes(props.analysis.status),
);

// Reload the analysis every 2 seconds until the background job is done.
const { start, stop } = usePoll(
    2000,
    { only: ['analysis'] },
    { autoStart: false },
);
watch(finished, (done) => (done ? stop() : start()), { immediate: true });

const progress = computed(() => {
    const { progressDone, progressTotal } = props.analysis;

    return progressTotal > 0
        ? Math.min(100, Math.round((progressDone / progressTotal) * 100))
        : 0;
});

// Live countdown while we wait for the Riot rate limit.
const now = ref(Date.now());
const ticker = setInterval(() => (now.value = Date.now()), 1000);
onBeforeUnmount(() => clearInterval(ticker));

const waitSeconds = computed(() => {
    if (props.analysis.status !== 'waiting' || !props.analysis.waitingUntil) {
        return 0;
    }

    return Math.max(
        0,
        Math.ceil(
            (new Date(props.analysis.waitingUntil).getTime() - now.value) /
                1000,
        ),
    );
});

// Remaining requests at the development key's 100 per 2 minutes, as a rough ETA.
const etaMinutes = computed(() => {
    const left = props.analysis.progressTotal - props.analysis.progressDone;

    return Math.ceil((left / 100) * 2);
});

const result = computed(() => props.analysis.result);

// Lists show their first few items; the rest behind "Show all".
const OPEN_COMPS_SHOWN = 6;
const CHAMPIONS_SHOWN = 8;
const showAllContested = ref(false);
const visibleContested = computed(() => {
    const champions = result.value?.contested ?? [];

    return showAllContested.value
        ? champions
        : champions.slice(0, CHAMPIONS_SHOWN);
});
const showAllOpenComps = ref(false);
const visibleOpenComps = computed(() => {
    const comps = result.value?.openComps ?? [];

    return showAllOpenComps.value ? comps : comps.slice(0, OPEN_COMPS_SHOWN);
});

const opponents = computed(
    () => result.value?.players.filter((p) => !p.isSubject) ?? [],
);

// Share of a champion's pool, as a CSS width (capped at 100%).
function poolWidth(copies: number, poolSize: number): string {
    return `${Math.min(100, (copies / poolSize) * 100)}%`;
}

function playerLink(gameName: string | null, tagLine: string | null) {
    return gameName && tagLine
        ? showPlayer([props.analysis.player.platform, `${gameName}-${tagLine}`])
        : null;
}
</script>

<template>
    <Head title="Lobby analysis" />

    <div class="flex flex-col gap-6">
        <header>
            <Link
                :href="
                    showPlayer([analysis.player.platform, analysis.player.slug])
                "
                class="text-sm text-muted-foreground hover:underline"
            >
                ← {{ analysis.player.gameName }}#{{ analysis.player.tagLine }}
            </Link>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight">
                Lobby analysis
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                <template v-if="analysis.source === 'match'">
                    Based on each player's games before match
                    {{ analysis.sourceId }}, compared with what they actually
                    played.
                </template>
                <template v-else-if="analysis.source === 'manual'">
                    Lobby entered by hand. Based on each player's recent games.
                </template>
                <template v-else>
                    Based on each player's recent games.
                </template>
            </p>
            <p
                v-if="result?.bracket"
                class="mt-1 text-xs text-muted-foreground"
            >
                <template v-if="result.bracket.comparedWith">
                    {{ result.bracket.lobby }} lobby, compared with
                    {{ result.bracket.comparedWith }} games ({{
                        result.bracket.boards
                    }}
                    boards).
                </template>
                <template v-else>
                    Compared with games of all ranks ({{
                        result.bracket.boards
                    }}
                    boards)<template v-if="result.bracket.lobby">
                        : not enough {{ result.bracket.lobby }} games
                        yet</template
                    >.
                </template>
            </p>
        </header>

        <section
            v-if="!finished"
            class="rounded-xl border border-sidebar-border/70 p-5 dark:border-sidebar-border"
        >
            <div class="mb-2 flex items-center justify-between text-sm">
                <span class="flex items-center gap-2">
                    <Spinner />
                    <template v-if="analysis.status === 'waiting'">
                        Waiting for the Riot rate limit, continuing in
                        {{ waitSeconds }}s
                    </template>
                    <template v-else-if="analysis.status === 'queued'">
                        Waiting to start…
                    </template>
                    <template v-else>{{ analysis.message }}</template>
                </span>
                <span class="text-muted-foreground tabular-nums">
                    {{ analysis.progressDone }} /
                    {{ analysis.progressTotal }} requests · {{ progress }}%
                </span>
            </div>
            <div class="h-2.5 overflow-hidden rounded-full bg-muted">
                <div
                    :class="
                        cn(
                            'h-full rounded-full transition-all duration-500',
                            analysis.status === 'waiting'
                                ? 'animate-pulse bg-cost-5'
                                : 'bg-cost-3',
                        )
                    "
                    :style="{ width: `${progress}%` }"
                />
            </div>
            <p class="mt-2 text-xs text-muted-foreground">
                Riot limits how fast data can be loaded, so this pauses when the
                limit is reached and continues automatically. You can leave this
                page open.
                <template v-if="etaMinutes > 1">
                    Roughly {{ etaMinutes }} minutes left at the current limit.
                </template>
            </p>
        </section>

        <div
            v-if="analysis.status === 'failed'"
            class="rounded-lg border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm"
        >
            {{ analysis.message }}
        </div>

        <div
            v-if="result?.notFound?.length"
            class="rounded-lg border border-cost-5/40 bg-cost-5/10 px-4 py-3 text-sm"
        >
            Not found on this server, so left out:
            {{ result.notFound.join(', ') }}
        </div>

        <template v-if="result">
            <div class="grid gap-6 lg:grid-cols-2">
                <div class="flex flex-col gap-6">
                    <section
                        class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                    >
                        <h2 class="text-sm font-semibold">
                            Likely contested champions
                        </h2>
                        <p class="mb-3 text-xs text-muted-foreground">
                            Expected copies the opponents take out of each
                            champion's pool (1★ = 1, 2★ = 3, 3★ = 9), out of the
                            pool size. The bar is the share of the pool; the
                            grey mark is the usual level in this set, red means
                            clearly more contested than usual. Every champion
                            taken at least as much as usual is here; the rest
                            are under free champions.
                        </p>
                        <ul class="flex flex-col gap-1.5">
                            <li
                                v-for="champ in visibleContested"
                                :key="champ.id"
                                class="flex items-center gap-2 text-sm"
                                :title="
                                    [
                                        `Usually ${champ.usualCopies} of ${champ.poolSize} copies taken`,
                                        ...champ.players.map(
                                            (p) =>
                                                `${p.name}: ${p.copies} copies`,
                                        ),
                                    ].join('\n')
                                "
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
                                <span class="w-24 truncate">{{
                                    champ.name
                                }}</span>
                                <div
                                    class="relative h-2 flex-1 rounded-full bg-muted"
                                >
                                    <div
                                        :class="
                                            cn(
                                                'h-full rounded-full',
                                                champ.expectedCopies >
                                                    champ.usualCopies * 1.15
                                                    ? 'bg-destructive/70'
                                                    : 'bg-muted-foreground/50',
                                            )
                                        "
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
                                            left: poolWidth(
                                                champ.usualCopies,
                                                champ.poolSize,
                                            ),
                                        }"
                                    />
                                </div>
                                <span
                                    class="w-20 text-right text-xs text-muted-foreground tabular-nums"
                                >
                                    {{ champ.expectedCopies.toFixed(1) }}
                                    <span class="opacity-70"
                                        >/ {{ champ.poolSize }}</span
                                    >
                                </span>
                            </li>
                        </ul>
                        <ShowMoreButton
                            v-if="result.contested.length > CHAMPIONS_SHOWN"
                            v-model="showAllContested"
                            :total="result.contested.length"
                            what="champions"
                        />
                    </section>
                    <FreeChampions :champions="result.free ?? []" />
                </div>

                <section
                    class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                >
                    <h2 class="text-sm font-semibold">
                        {{
                            result.anyOpen === false
                                ? 'Comps for this lobby'
                                : 'Likely open comps'
                        }}
                    </h2>
                    <p
                        v-if="result.anyOpen === false"
                        class="mt-1 rounded-md bg-muted/60 px-2 py-1.5 text-xs"
                    >
                        No comp is clearly more open than usual in this lobby,
                        so these are sorted by how well they place.
                    </p>
                    <p class="mb-3 text-xs text-muted-foreground">
                        Comps that place well and whose key champions are
                        easiest to hit in this lobby. ★ = 3★ target, sword =
                        carry or item tank; filler units are ignored. Hover a
                        champion for the details.
                    </p>
                    <p
                        v-if="result.openComps.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        Not enough comp data yet. Run
                        <code>php artisan tft:crawl-meta</code> to import
                        high-elo games.
                    </p>
                    <ul class="flex flex-col gap-4">
                        <OpenCompCard
                            v-for="comp in visibleOpenComps"
                            :key="comp.key"
                            :comp="comp"
                        />
                    </ul>
                    <ShowMoreButton
                        v-if="result.openComps.length > OPEN_COMPS_SHOWN"
                        v-model="showAllOpenComps"
                        :total="result.openComps.length"
                        what="comps"
                    />
                </section>
            </div>

            <section class="flex flex-col gap-3">
                <h2 class="text-sm font-semibold">Players</h2>
                <div class="grid gap-4 md:grid-cols-2">
                    <article
                        v-for="p in [
                            ...result.players.filter((x) => x.isSubject),
                            ...opponents,
                        ]"
                        :key="p.puuid"
                        class="flex flex-col gap-3"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <div class="min-w-0">
                                <component
                                    :is="
                                        playerLink(p.gameName, p.tagLine)
                                            ? Link
                                            : 'span'
                                    "
                                    :href="
                                        playerLink(p.gameName, p.tagLine) ??
                                        undefined
                                    "
                                    class="truncate font-medium hover:underline"
                                >
                                    {{ p.gameName ?? 'Unknown'
                                    }}<span class="text-muted-foreground"
                                        >#{{ p.tagLine }}</span
                                    >
                                </component>
                                <span
                                    v-if="p.isSubject"
                                    class="ml-2 text-xs text-muted-foreground"
                                    >(searched player)</span
                                >
                                <div class="text-xs text-muted-foreground">
                                    {{ rankLabel(p.rank) }} ·
                                    {{ p.playstyle.games }} games analysed ·
                                    <span
                                        title="How much of this player's prediction comes from their own games. The rest comes from what is popular overall: the more different comps someone plays, the less their history says."
                                        >own history
                                        {{
                                            Math.round(p.historyWeight * 100)
                                        }}%</span
                                    >
                                </div>
                            </div>
                            <div
                                v-if="p.actual"
                                class="flex shrink-0 items-center gap-2 text-xs"
                                title="What this player actually played in the analysed match"
                            >
                                <span class="text-muted-foreground"
                                    >Played</span
                                >
                                <span class="max-w-28 truncate font-medium">{{
                                    p.actual.label
                                }}</span>
                                <span
                                    :class="
                                        cn(
                                            'rounded px-1.5 py-0.5 font-semibold',
                                            placementClass(p.actual.placement),
                                        )
                                    "
                                >
                                    {{ ordinal(p.actual.placement) }}
                                </span>
                            </div>
                        </div>

                        <div
                            v-if="p.likelyComps.length"
                            class="rounded-lg bg-muted/50 p-3"
                        >
                            <div
                                class="mb-1.5 text-[11px] text-muted-foreground"
                            >
                                Likely comps
                            </div>
                            <ul class="flex flex-col gap-1">
                                <li
                                    v-for="comp in p.likelyComps"
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
                                    <span
                                        :class="
                                            cn(
                                                'flex-1 truncate',
                                                p.actual?.key === comp.key &&
                                                    'font-semibold text-cost-2',
                                            )
                                        "
                                    >
                                        {{ comp.label }}
                                        <template
                                            v-if="p.actual?.key === comp.key"
                                            >✓</template
                                        >
                                    </span>
                                    <span
                                        class="text-xs text-muted-foreground tabular-nums"
                                    >
                                        {{ comp.playedBefore }}× ·
                                        {{
                                            Math.round(comp.probability * 100)
                                        }}%
                                    </span>
                                </li>
                            </ul>
                        </div>

                        <PlaystyleCard :playstyle="p.playstyle" />
                    </article>
                </div>
            </section>
        </template>
    </div>
</template>
