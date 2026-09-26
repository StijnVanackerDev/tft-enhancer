// Fake lobby for developing the UI outside the game. It emits GEP-shaped info
// updates and runs them through the real tracker, so parsing is exercised too.
import { createState, applyInfoUpdate } from '../lib/tracker.js'
import data from '../data/champions.json'

const OPPONENTS = ['Bluefox', 'Kappa Kid', 'NoScope', 'Pengu Main', 'Rollbot', 'Tacticoo', 'Zephyr']
const STAGES = ['2-1', '2-5', '3-2', '3-5', '4-1', '4-5', '5-1', '5-5']

const pick = (list) => list[Math.floor(Math.random() * list.length)]

function randomPieces(count, maxCost) {
  const pool = data.champions.filter((c) => c.cost <= maxCost)
  const pieces = {}
  for (let i = 1; i <= count; i++) {
    const level = Math.random() < 0.25 ? 2 : Math.random() < 0.03 ? 3 : 1
    pieces[`cell_${i}`] = { name: pick(pool).apiName, level: String(level) }
  }
  return JSON.stringify(pieces)
}

export function startMockGame(onState) {
  const state = createState()
  let tick = 0

  const emit = (info) => {
    applyInfoUpdate(state, { info })
    onState(JSON.parse(JSON.stringify(state)))
  }

  emit({ match_info: { pseudo_match_id: 'mock-match' }, me: { summoner_name: 'You#DEV' } })

  const step = () => {
    const stageIndex = Math.min(Math.floor(tick / 3), STAGES.length - 1)
    const units = 3 + stageIndex
    const maxCost = Math.min(5, 1 + Math.floor(stageIndex / 1.5))
    const stage = STAGES[stageIndex]

    emit({
      match_info: { round_type: JSON.stringify({ stage, name: 'Combat', type: 'PVP' }) },
      board: { board_pieces: randomPieces(units, maxCost) },
      bench: { bench_pieces: randomPieces(3, maxCost) },
      store: { shop_pieces: randomPieces(5, maxCost).replaceAll('cell_', 'slot_') },
    })

    // "Scout" one opponent per tick.
    emit({ match_info: { board_spectate: `${pick(OPPONENTS)}#EUW` } })
    emit({ board: { opponent_board_pieces: randomPieces(units, maxCost) } })
    tick++
  }

  step()
  const timer = setInterval(step, 2500)
  return () => clearInterval(timer)
}
