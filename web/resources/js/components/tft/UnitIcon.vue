<script setup lang="ts">
import { cn } from '@/lib/utils';
import { costBorderClass, costTextClass } from '@/lib/tft';
import type { Unit } from '@/types';

defineProps<{
    unit: Unit;
}>();
</script>

<template>
    <div class="flex w-11 flex-col items-center" :title="unit.name">
        <span
            :class="
                cn(
                    'h-3 text-[10px] leading-3 tracking-tighter',
                    costTextClass(unit.cost),
                )
            "
        >
            {{ '★'.repeat(unit.stars) }}
        </span>
        <div
            :class="
                cn(
                    'size-10 overflow-hidden rounded-md border-2 bg-muted',
                    costBorderClass(unit.cost),
                )
            "
        >
            <img
                v-if="unit.icon"
                :src="unit.icon"
                :alt="unit.name"
                loading="lazy"
                class="size-full object-cover"
            />
            <span
                v-else
                class="flex size-full items-center justify-center text-[9px] leading-tight"
            >
                {{ unit.name }}
            </span>
        </div>
        <div class="mt-0.5 flex h-3.5 gap-px">
            <img
                v-for="(item, i) in unit.items"
                :key="i"
                :src="item.icon ?? undefined"
                :alt="item.name"
                :title="item.name"
                loading="lazy"
                class="size-3.5 rounded-sm bg-muted"
            />
        </div>
    </div>
</template>
