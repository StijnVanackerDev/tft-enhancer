import { test } from 'node:test'
import assert from 'node:assert/strict'
import { createState, applyInfoUpdate, applyEvents, computePool, copiesForStar, parsePieces } from '../src/lib/tracker.js'

const champions = [
  { apiName: 'DA_18_Varus', name: 'Varus', cost: 1, traits: [] },
  { apiName: 'DA_18_Sentry', name: 'Pebbles', cost: 1, traits: [] },
  { apiName: 'DA_18_Ahri', name: 'Ahri', cost: 4, traits: [] },
]
const sizes = { 1: 30, 4: 10 }
const rowFor = (pool, apiName) => pool.rows.find((r) => r.apiName === apiName)

test('star level converts to copies', () => {
  assert.equal(copiesForStar(1), 1)
  assert.equal(copiesForStar('2'), 3)
  assert.equal(copiesForStar(3), 9)
})

test('parses GEP piece strings', () => {
  const pieces = parsePieces('{"cell_1":{"name":"DA_18_Varus","level":"2","item_1":""},"cell_2":{"name":"","level":"0"}}')
  assert.deepEqual(pieces, [{ name: 'DA_18_Varus', level: 2 }])
})

test('counts own board, bench and scouted opponents', () => {
  const state = createState()
  applyInfoUpdate(state, { info: { me: { summoner_name: 'Me#EUW' } } })
  applyInfoUpdate(state, {
    info: {
      board: { board_pieces: JSON.stringify({ cell_1: { name: 'DA_18_Varus', level: '2' } }) },
      bench: { bench_pieces: JSON.stringify({ cell_1: { name: 'DA_18_Varus', level: '1' } }) },
    },
  })
  applyInfoUpdate(state, {
    info: {
      match_info: { board_spectate: 'Enemy#1111', round_type: '{"stage":"3-2","name":"Combat","type":"PVP"}' },
      board: { opponent_board_pieces: JSON.stringify({ cell_11: { name: 'DA_18_Varus', level: '1' } }) },
    },
  })

  const pool = computePool(state, champions, sizes)
  const varus = rowFor(pool, 'DA_18_Varus')
  assert.equal(varus.taken, 5)
  assert.equal(varus.remaining, 25)
  assert.deepEqual(varus.holders.map((h) => [h.player, h.copies, h.seenStage]), [
    ['You', 4, 'now'],
    ['Enemy', 1, '3-2'],
  ])
})

test('does not attribute your own board to an opponent when spectating yourself', () => {
  const state = createState()
  applyInfoUpdate(state, { info: { me: { summoner_name: 'Me' }, match_info: { board_spectate: 'Me#EUW' } } })
  applyInfoUpdate(state, { info: { board: { opponent_board_pieces: '{"cell_1":{"name":"DA_18_Ahri","level":"1"}}' } } })
  assert.equal(rowFor(computePool(state, champions, sizes), 'DA_18_Ahri').taken, 0)
})

test('eliminated players return their units to the pool', () => {
  const state = createState()
  applyInfoUpdate(state, {
    info: {
      match_info: { board_spectate: 'Enemy' },
      board: { opponent_board_pieces: '{"cell_1":{"name":"DA_18_Ahri","level":"2"}}' },
    },
  })
  assert.equal(rowFor(computePool(state, champions, sizes), 'DA_18_Ahri').taken, 3)

  applyInfoUpdate(state, { info: { roster: { player_status: '{"Enemy":{"health":0,"localplayer":false}}' } } })
  assert.equal(rowFor(computePool(state, champions, sizes), 'DA_18_Ahri').taken, 0)
})

test('board_players snapshot fills in every opponent, including benches', () => {
  const state = createState()
  applyInfoUpdate(state, { info: { me: { summoner_name: 'Me' } } })
  applyInfoUpdate(state, {
    info: {
      match_stats: {
        board_players: [
          { stage: { stage_main: 1, stage_sub: 2 }, board: [{ summoner: 'A', board: [{ name: 'DA_18_Sentry', level: 1 }], bench: [] }] },
          {
            stage: { stage_main: 2, stage_sub: 1 },
            board: [
              { summoner: 'A', tag_line: '1', board: [{ name: 'DA_18_Sentry', level: 2 }], bench: [{ name: 'DA_18_Sentry', level: 1 }] },
              { summoner: 'Me', board: [{ name: 'DA_18_Sentry', level: 3 }], bench: [] },
            ],
          },
        ],
      },
    },
  })
  const sentry = rowFor(computePool(state, champions, sizes), 'DA_18_Sentry')
  assert.equal(sentry.taken, 4) // A's 2★ + 1★; our own entry is ignored in favour of board_pieces
  assert.equal(sentry.holders[0].seenStage, '2-1')
})

test('new match resets everything', () => {
  const state = createState()
  applyInfoUpdate(state, { info: { board: { board_pieces: '{"cell_1":{"name":"DA_18_Ahri","level":"1"}}' } } })
  applyEvents(state, { events: [{ name: 'match_start', data: '' }] })
  assert.equal(rowFor(computePool(state, champions, sizes), 'DA_18_Ahri').taken, 0)
})

test('unknown unit names are reported instead of silently dropped', () => {
  const state = createState()
  applyInfoUpdate(state, { info: { board: { board_pieces: '{"cell_1":{"name":"TFT99_Mystery","level":"1"}}' } } })
  assert.deepEqual(computePool(state, champions, sizes).unknown, ['TFT99_Mystery'])
})
