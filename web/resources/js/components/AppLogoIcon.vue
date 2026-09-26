<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 40 42"
        :class="className"
        v-bind="$attrs"
    >
        <path
            fill="currentColor"
            fill-rule="evenodd"
            d="M20 1 38 11.5v19L20 41 2 30.5v-19L20 1Zm0 9-10 5.75v11.5L20 33l10-5.75v-11.5L20 10Z"
        />
    </svg>
</template>
