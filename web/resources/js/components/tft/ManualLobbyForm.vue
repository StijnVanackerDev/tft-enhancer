<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { MonitorPlay, Users } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/lobby';

// Enter a lobby by hand, for when live game lookups aren't available.
defineProps<{
    playerId: number;
    riotId: string;
}>();
</script>

<template>
    <section
        class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
    >
        <h2 class="mb-1 flex items-center gap-2 text-sm font-semibold">
            <Users class="size-4" />
            Analyse a lobby
        </h2>
        <Form
            v-bind="store.form(playerId)"
            v-slot="{ processing, errors }"
            class="mb-4 flex flex-col gap-1.5"
        >
            <input type="hidden" name="source" value="client" />
            <Button type="submit" size="sm" :disabled="processing">
                <Spinner v-if="processing" />
                <MonitorPlay v-else />
                Load my current game
            </Button>
            <p class="text-xs text-muted-foreground">
                Reads the players from the Riot client on this PC while you're
                in a game.
            </p>
            <InputError :message="errors.source" />
        </Form>

        <p class="mb-3 text-xs text-muted-foreground">
            Or enter the other players' Riot IDs (Name#TAG), one per line or
            separated by commas. {{ riotId }} is always included.
        </p>
        <Form
            v-bind="store.form(playerId)"
            v-slot="{ processing, errors }"
            class="flex flex-col gap-2"
        >
            <input type="hidden" name="source" value="manual" />
            <textarea
                name="names"
                rows="7"
                required
                placeholder="Player One#EUW&#10;Player Two#1234&#10;…"
                class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
            />
            <InputError :message="errors.names" />
            <Button type="submit" size="sm" :disabled="processing">
                <Spinner v-if="processing" />
                Analyse lobby
            </Button>
        </Form>
    </section>
</template>
