<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ChartColumn, History, Swords } from '@lucide/vue';
import PlayerSearch from '@/components/tft/PlayerSearch.vue';

const features = [
    {
        icon: History,
        title: 'Match history',
        text: 'Your final boards, traits, items and placements for every recent game.',
    },
    {
        icon: ChartColumn,
        title: 'Personal stats',
        text: 'Average placement and top 4 rate per unit and trait, based only on your own games.',
    },
    {
        icon: Swords,
        title: 'Learn from yourself',
        text: 'See what actually works for you instead of copying whatever the meta says.',
    },
];
</script>

<template>
    <Head title="TFT match history & personal stats" />

    <section
        class="mx-auto flex max-w-2xl flex-col items-center py-12 text-center"
    >
        <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">
            Get better at TFT by learning from your own games
        </h1>
        <p class="mt-3 text-muted-foreground">
            Look up any Riot ID to see recent matches and personal stats.
        </p>
        <div class="mt-8 w-full">
            <PlayerSearch />
        </div>
    </section>

    <section class="grid gap-4 sm:grid-cols-3">
        <div
            v-for="feature in features"
            :key="feature.title"
            class="rounded-xl border border-sidebar-border/70 p-5 dark:border-sidebar-border"
        >
            <component :is="feature.icon" class="size-5 text-cost-5" />
            <h2 class="mt-3 font-medium">{{ feature.title }}</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ feature.text }}
            </p>
        </div>
    </section>
</template>
