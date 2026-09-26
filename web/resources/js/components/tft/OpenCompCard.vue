<script setup lang="ts">
import { Star, Sword } from '@lucide/vue';
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import { costBorderClass } from '@/lib/tft';
import type { OpenComp } from '@/types';

const props = defineProps<{
    comp: OpenComp;
}>();

// How this lobby compares with a usual one for this comp's key units.
const verdict = computed(() => {
    const d = props.comp.difficulty;

    if (props.comp.rolls === null) {
        return { text: 'Not enough copies left', class: 'text-destructive' };
    }

    if (d === null || Math.abs(d - 1) < 0.05) {
        return { text: 'As open as usual', class: 'text-muted-foreground' };
    }

    const pct = Math.round(Math.abs(d - 1) * 100);

    return d < 1
        ? { text: `${pct}% easier than usual`, class: 'text-cost-2' }
        : { text: `${pct}% harder than usual`, class: 'text-destructive' };
});
</script>

<template>
    <li class="flex flex-col gap-1.5">
        <div class="flex items-center gap-2 text-sm">
            <img
                v-if="comp.icon"
                :src="comp.icon"
                :alt="comp.label"
                class="size-6 rounded object-cover"
                loading="lazy"
            />
            <span class="font-medium">{{ comp.label }}</span>
            <span v-if="comp.levelling" class="text-xs text-muted-foreground">
                {{ comp.levelling }}
            </span>
            <span
                class="ml-auto text-xs text-muted-foreground tabular-nums"
                :title="`Average placement over ${comp.games} games`"
            >
                avg {{ comp.avgPlacement?.toFixed(2) ?? '–' }}
            </span>
        </div>

        <div class="flex flex-wrap gap-1.5">
            <div
                v-for="unit in comp.keyUnits"
                :key="unit.id"
                class="flex items-center gap-1.5 rounded-md bg-muted/50 py-0.5 pr-2 pl-0.5 text-xs"
                :title="`${unit.name}: needs ${unit.needed} of ${unit.poolSize} copies, ~${unit.left} left after the opponents. About ${unit.rolls ?? '∞'} shops at level ${unit.level} (usually ${unit.usualRolls ?? '∞'}).`"
            >
                <img
                    :src="unit.icon ?? undefined"
                    :alt="unit.name"
                    loading="lazy"
                    :class="
                        cn(
                            'size-6 rounded border-2 object-cover',
                            costBorderClass(unit.cost),
                        )
                    "
                />
                <Star
                    v-if="unit.role === 'target'"
                    class="size-3 fill-cost-5 text-cost-5"
                />
                <Sword v-else class="size-3 text-muted-foreground" />
                <span class="tabular-nums">
                    {{
                        unit.needed === 9
                            ? '3★'
                            : unit.needed === 3
                              ? '2★'
                              : '1★'
                    }}
                    · {{ Math.round(unit.left) }}/{{ unit.poolSize }} left
                </span>
            </div>
        </div>

        <div class="text-xs">
            <span :class="verdict.class">{{ verdict.text }}</span>
            <span
                v-if="comp.rolls !== null"
                class="text-muted-foreground"
                title="Shops at the comp's roll level to find all needed copies of the hardest key unit from scratch. Use it to compare comps and lobbies, not as an exact gold amount."
            >
                · ~{{ Math.round(comp.rolls) }} shops at level
                {{ comp.rollLevel }}
            </span>
        </div>
    </li>
</template>
