// Downloads champion data for a TFT set from Community Dragon and writes a
// trimmed copy to src/data/champions.json.
//
//   npm run fetch-data          -> newest set in the data
//   npm run fetch-data -- 17    -> a specific set number
//
// "latest" on Community Dragon follows the PBE, so the newest set may not be
// live yet. Pass the live set number if that's the case.
import { writeFileSync } from 'node:fs'

const SOURCE = 'https://raw.communitydragon.org/latest/cdragon/tft/en_us.json'
const ASSET_BASE = 'https://raw.communitydragon.org/latest/game/'
const OUT = new URL('../src/data/champions.json', import.meta.url)

// Units that exist in the data but are never in the shop (PVE monsters,
// trackers, trait clones, summons, ...).
const NOT_IN_SHOP = /Tracker|FakeUnit|TraitClone|Enemy_|Summon|_PVE_|Minion/i

const requestedSet = process.argv[2] ? Number(process.argv[2]) : null

console.log(`Downloading ${SOURCE} ...`)
const res = await fetch(SOURCE)
if (!res.ok) throw new Error(`Download failed: ${res.status} ${res.statusText}`)
const data = await res.json()

const baseSets = data.setData.filter((s) => /^TFTSet\d+$/.test(s.mutator))
const set = requestedSet
  ? baseSets.find((s) => s.number === requestedSet)
  : baseSets.sort((a, b) => b.number - a.number)[0]

if (!set) {
  const available = baseSets.map((s) => s.number).sort((a, b) => a - b)
  throw new Error(`Set ${requestedSet} not found. Available: ${available.join(', ')}`)
}

const toAssetUrl = (path) =>
  path ? ASSET_BASE + path.toLowerCase().replace(/\.(tex|dds)$/, '.png') : null

const champions = set.champions
  .filter((c) => c.cost >= 1 && c.cost <= 5)
  .filter((c) => c.traits?.length > 0)
  .filter((c) => !NOT_IN_SHOP.test(c.apiName))
  .map((c) => ({
    apiName: c.apiName,
    name: c.name,
    cost: c.cost,
    traits: c.traits,
    icon: toAssetUrl(c.squareIcon || c.tileIcon),
  }))
  .sort((a, b) => a.cost - b.cost || a.name.localeCompare(b.name))

writeFileSync(
  OUT,
  JSON.stringify(
    { set: set.number, mutator: set.mutator, fetchedAt: new Date().toISOString(), champions },
    null,
    2,
  ) + '\n',
)

const perCost = champions.reduce((acc, c) => ({ ...acc, [c.cost]: (acc[c.cost] || 0) + 1 }), {})
console.log(`Set ${set.number}: wrote ${champions.length} champions`, perCost)
