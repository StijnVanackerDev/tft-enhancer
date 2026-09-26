<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Radio } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDuration } from '@/lib/tft';
import { store } from '@/routes/lobby';
import type { ActiveGame } from '@/types';

defineProps<{
    playerId: number;
    game: ActiveGame;
}>();
</script>

<template>
    <section
        v-if="game.status === 'in_game'"
        class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
    >
        <h2 class="mb-2 flex items-center gap-2 text-sm font-semibold">
            <Radio class="size-4 text-destructive" />
            In game now
            <span class="font-normal text-muted-foreground">
                · {{ formatDuration(game.gameLength) }}
            </span>
        </h2>

        <template v-if="game.status === 'in_game'">
            <ul class="mb-3 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                <li
                    v-for="p in game.players"
                    :key="p.riotId"
                    :class="{ 'font-semibold': p.isSubject }"
                    class="truncate"
                >
                    {{ p.riotId }}
                </li>
            </ul>
            <Form v-bind="store.form(playerId)" v-slot="{ processing, errors }">
                <input type="hidden" name="source" value="live" />
                <Button type="submit" size="sm" :disabled="processing">
                    <Spinner v-if="processing" />
                    Analyse this lobby
                </Button>
                <InputError :message="errors.source" class="mt-1" />
            </Form>
        </template>
    </section>
</template>
