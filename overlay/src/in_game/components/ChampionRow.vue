<script setup>
import { computed } from 'vue'

const props = defineProps({
  row: { type: Object, required: true },
  inShop: { type: Boolean, default: false },
})

const percentLeft = computed(() => (props.row.total ? (props.row.remaining / props.row.total) * 100 : 0))

const tooltip = computed(() =>
  props.row.holders.length
    ? props.row.holders.map((h) => `${h.player}: ${h.copies} (seen ${h.seenStage ?? '?'})`).join('\n')
    : 'Nobody holds this unit (as far as we know)',
)
</script>

<template>
  <div :class="['row', { 'in-shop': inShop, contested: row.holders.length >= 2 }]" :title="tooltip">
    <img v-if="row.icon" :src="row.icon" :alt="row.name" loading="lazy" />
    <div class="info">
      <div class="line">
        <span class="name">{{ row.name }}</span>
        <span class="count">
          <strong>{{ row.remaining }}</strong>/{{ row.total }} left
        </span>
      </div>
      <div class="bar">
        <div :class="['fill', `cost-bg-${row.cost}`]" :style="{ width: percentLeft + '%' }" />
      </div>
      <div v-if="row.holders.length" class="holders">
        <span v-for="h in row.holders" :key="h.player">{{ h.player }} ×{{ h.copies }}</span>
      </div>
    </div>
  </div>
</template>
