<script setup lang="ts">
import { Form, usePage } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { lookup } from '@/routes/players';
import type { PlatformOption } from '@/types';

const { compact = false } = defineProps<{
    compact?: boolean;
}>();

const page = usePage<{ platforms?: PlatformOption[] }>();

// The platform list is shared by the backend on every page.
const platforms = (page.props.platforms ?? []) as PlatformOption[];
</script>

<template>
    <Form
        v-bind="lookup.form()"
        v-slot="{ errors, processing }"
        :class="
            cn('flex flex-col gap-1', compact ? 'w-full max-w-md' : 'w-full')
        "
    >
        <div class="flex gap-2">
            <Input
                name="riot_id"
                required
                :placeholder="compact ? 'Name#TAG' : 'Riot ID, e.g. Name#EUW'"
                autocomplete="off"
                aria-label="Riot ID"
                :class="compact ? 'h-8' : 'h-11 text-base'"
            />
            <select
                name="platform"
                aria-label="Server"
                :class="
                    cn(
                        'rounded-md border border-input bg-transparent px-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30',
                        compact ? 'h-8' : 'h-11',
                    )
                "
            >
                <option
                    v-for="platform in platforms"
                    :key="platform.value"
                    :value="platform.value"
                    class="bg-background"
                >
                    {{ platform.label }}
                </option>
            </select>
            <Button
                type="submit"
                :disabled="processing"
                :size="compact ? 'sm' : 'lg'"
                :class="compact ? '' : 'h-11'"
            >
                <Spinner v-if="processing" />
                <Search v-else />
                <span :class="compact ? 'sr-only' : ''">Search</span>
            </Button>
        </div>
        <InputError :message="errors.riot_id ?? errors.platform" />
    </Form>
</template>
