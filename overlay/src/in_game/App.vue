<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'
import data from '../data/champions.json'
import { POOL_SIZES } from '../lib/poolSizes.js'
import { computePool } from '../lib/tracker.js'
import { connect, dragWindow, minimizeWindow, isOverwolf } from './source.js'
import ChampionRow from './components/ChampionRow.vue'

const state = ref(null)
const search = ref('')
const onlyTaken = ref(false)
const costs = ref([1, 2, 3, 4, 5])

const disconnect = connect((s) => (state.value = s))
onBeforeUnmount(() => disconnect?.())

const pool = computed(() =>
  state.value ? computePool(state.value, data.champions, POOL_SIZES) : { rows: [], unknown: [] },
)

const shop = computed(() => new Set((state.value?.shop ?? []).map((n) => n.toLowerCase())))

const groups = computed(() => {
  const term = search.value.trim().toLowerCase()
  const rows = pool.value.rows.filter(
    (r) =>
      costs.value.includes(r.cost) &&
      (!onlyTaken.value || r.taken > 0) &&
      (!term || r.name.toLowerCase().includes(term) || r.traits.some((t) => t.toLowerCase().includes(term))),
  )
  return [1, 2, 3, 4, 5]
    .map((cost) => ({
      cost,
      rows: rows.filter((r) => r.cost === cost).sort((a, b) => b.taken - a.taken || a.name.localeCompare(b.name)),
    }))
    .filter((g) => g.rows.length > 0)
})

const scouted = computed(() =>
  Object.values(state.value?.players ?? {}).filter((p) => !p.eliminated && p.seenStage),
)

function toggleCost(cost) {
  costs.value = costs.value.includes(cost) ? costs.value.filter((c) => c !== cost) : [...costs.value, cost]
}
</script>

<template>
  <div class="overlay">
    <header @mousedown="dragWindow">
      <span class="title">Pool tracker</span>
      <span class="meta">Set {{ data.set }} · Stage {{ state?.stage ?? '–' }}</span>
      <button v-if="isOverwolf" class="icon-btn" title="Hide (Ctrl+Shift+P)" @mousedown.stop @click="minimizeWindow">
        –
      </button>
    </header>

    <div v-if="!isOverwolf" class="notice">Browser preview: showing a simulated lobby.</div>

    <div class="filters">
      <input v-model="search" type="search" placeholder="Champion or trait…" />
      <div class="cost-toggles">
        <button
          v-for="cost in [1, 2, 3, 4, 5]"
          :key="cost"
          :class="['cost-btn', `cost-${cost}`, { off: !costs.includes(cost) }]"
          @click="toggleCost(cost)"
        >
          {{ cost }}
        </button>
      </div>
      <label class="check"><input v-model="onlyTaken" type="checkbox" /> Taken only</label>
    </div>

    <div class="scouted">
      Scouted {{ scouted.length }}/7:
      <span v-for="p in scouted" :key="p.name" class="player">{{ p.name }} <small>({{ p.seenStage }})</small></span>
    </div>

    <main>
      <section v-for="group in groups" :key="group.cost">
        <h2 :class="`cost-${group.cost}`">{{ group.cost }}-cost · {{ POOL_SIZES[group.cost] }} each</h2>
        <ChampionRow
          v-for="row in group.rows"
          :key="row.apiName"
          :row="row"
          :in-shop="shop.has(row.apiName.toLowerCase())"
        />
      </section>
    </main>

    <footer v-if="pool.unknown.length" class="warning">
      Unrecognised units (check the set in champions.json): {{ pool.unknown.join(', ') }}
    </footer>
  </div>
</template>
