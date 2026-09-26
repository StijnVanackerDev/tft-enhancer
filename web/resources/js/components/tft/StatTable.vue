<script setup lang="ts">
import { cn } from '@/lib/utils';
import { costBorderClass } from '@/lib/tft';
import type { StatRow } from '@/types';

defineProps<{
    title: string;
    rows: StatRow[];
    square?: boolean;
}>();
</script>

<template>
    <section
        class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
    >
        <h2 class="mb-3 text-sm font-semibold">{{ title }}</h2>
        <p v-if="rows.length === 0" class="text-sm text-muted-foreground">
            No games yet.
        </p>
        <table v-else class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs text-muted-foreground">
                    <th class="pb-2 font-medium">Name</th>
                    <th class="pb-2 text-right font-medium">Games</th>
                    <th class="pb-2 text-right font-medium">Avg place</th>
                    <th class="pb-2 text-right font-medium">Top 4</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="row in rows"
                    :key="row.id"
                    class="border-t border-sidebar-border/50"
                >
                    <td class="py-1.5">
                        <div class="flex items-center gap-2">
                            <img
                                v-if="row.icon"
                                :src="row.icon"
                                :alt="row.name"
                                loading="lazy"
                                :class="
                                    cn(
                                        'size-6 object-cover',
                                        square
                                            ? [
                                                  'rounded border',
                                                  costBorderClass(
                                                      row.cost ?? 0,
                                                  ),
                                              ]
                                            : 'rounded-full bg-neutral-800 p-0.5',
                                    )
                                "
                            />
                            <span class="truncate">{{ row.name }}</span>
                        </div>
                    </td>
                    <td class="py-1.5 text-right tabular-nums">
                        {{ row.games }}
                    </td>
                    <td class="py-1.5 text-right tabular-nums">
                        {{ row.avgPlacement.toFixed(2) }}
                    </td>
                    <td class="py-1.5 text-right tabular-nums">
                        {{ row.top4Rate }}%
                    </td>
                </tr>
            </tbody>
        </table>
    </section>
</template>
