<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

// Toggle for lists that show their first few items and can expand.
defineProps<{
    total: number;
    what: string;
}>();

const expanded = defineModel<boolean>({ required: true });
</script>

<template>
    <Button
        variant="ghost"
        size="sm"
        class="mt-3 w-full"
        @click="expanded = !expanded"
    >
        <ChevronDown
            :class="cn('transition-transform', expanded && 'rotate-180')"
        />
        {{ expanded ? 'Show fewer' : `Show all ${total} ${what}` }}
    </Button>
</template>
