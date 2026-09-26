// Pure game-state tracking: turns Overwolf GEP info updates / events into a
// picture of which units every player holds. No Overwolf or Vue imports, so
// it can be unit tested with plain Node (see test/).
//
// GEP reference:
// https://dev.overwolf.com/ow-native/live-game-data-gep/supported-games/teamfight-tactics

export function createState() {
  return {
    matchId: null,
    stage: null,
    localKey: null,
    spectating: null,
    opponent: null,
    local: { board: [], bench: [] },
    shop: [],
    // playerKey -> { name, board, bench, seenStage, health, eliminated }
    players: {},
  }
}

export function resetState(state) {
  Object.assign(state, createState())
}

// "Name#TAG" / "Name" -> "name". Names are unique within a lobby.
export function playerKey(name) {
  return String(name ?? '').split('#')[0].trim().toLowerCase()
}

// A 2★ unit is 3 copies, a 3★ is 9, and so on.
export function copiesForStar(level) {
  const star = Math.max(1, Number(level) || 1)
  return 3 ** (star - 1)
}

// GEP sends most values as JSON strings; board_players sends real arrays.
function parseValue(value) {
  if (typeof value !== 'string') return value
  try {
    return JSON.parse(value)
  } catch {
    return value
  }
}

// Accepts { cell_1: { name, level }, ... } or [{ name, level }, ...].
export function parsePieces(value) {
  const parsed = parseValue(value)
  if (!parsed || typeof parsed !== 'object') return []
  const list = Array.isArray(parsed) ? parsed : Object.values(parsed)
  return list
    .filter((p) => p && p.name)
    .map((p) => ({ name: p.name, level: Number(p.level) || 1 }))
}

function player(state, name) {
  const key = playerKey(name)
  if (!key) return null
  state.players[key] ??= {
    name: String(name).split('#')[0],
    board: [],
    bench: [],
    seenStage: null,
    health: null,
    eliminated: false,
  }
  return state.players[key]
}

// Handles one onInfoUpdates2 payload: { feature, info: { <category>: { <key>: value } } }
export function applyInfoUpdate(state, update) {
  const info = update?.info ?? {}

  // Order matters: know whose board we're looking at before reading it.
  const matchInfo = info.match_info ?? {}
  if (matchInfo.pseudo_match_id && matchInfo.pseudo_match_id !== state.matchId) {
    resetState(state)
    state.matchId = matchInfo.pseudo_match_id
  }
  if (matchInfo.round_type) {
    state.stage = parseValue(matchInfo.round_type)?.stage ?? state.stage
  }
  if ('board_spectate' in matchInfo) {
    state.spectating = matchInfo.board_spectate || null
  }
  if (matchInfo.opponent) {
    state.opponent = parseValue(matchInfo.opponent)?.name || null
  }

  if (info.me?.summoner_name) {
    state.localKey = playerKey(info.me.summoner_name)
  }

  if (info.roster?.player_status) {
    const roster = parseValue(info.roster.player_status) ?? {}
    for (const [name, status] of Object.entries(roster)) {
      const p = player(state, name)
      if (!p) continue
      p.health = status.health
      p.eliminated = Number(status.health) <= 0
      if (status.localplayer) state.localKey = playerKey(name)
    }
  }

  if (info.store?.shop_pieces !== undefined) {
    state.shop = parsePieces(info.store.shop_pieces).map((p) => p.name)
  }

  if (info.board?.board_pieces !== undefined) {
    state.local.board = parsePieces(info.board.board_pieces)
  }
  if (info.bench?.bench_pieces !== undefined) {
    state.local.bench = parsePieces(info.bench.bench_pieces)
  }

  if (info.board?.opponent_board_pieces !== undefined) {
    const owner = state.spectating ?? state.opponent
    if (owner && playerKey(owner) !== state.localKey) {
      const p = player(state, owner)
      p.board = parsePieces(info.board.opponent_board_pieces)
      p.seenStage = state.stage
    }
  }

  if (info.match_stats?.board_players !== undefined) {
    applyBoardPlayers(state, info.match_stats.board_players)
  }
}

// board_players is a list of per-stage snapshots of every player's board and
// bench. Only the most recent stage matters for the current pool.
export function applyBoardPlayers(state, value) {
  const stages = parseValue(value)
  if (!Array.isArray(stages) || stages.length === 0) return
  const latest = stages[stages.length - 1]
  const stage = latest.stage ? `${latest.stage.stage_main}-${latest.stage.stage_sub}` : state.stage
  for (const entry of latest.board ?? []) {
    const p = player(state, entry.summoner)
    if (!p || playerKey(entry.summoner) === state.localKey) continue
    p.board = parsePieces(entry.board)
    p.bench = parsePieces(entry.bench)
    p.seenStage = stage
  }
}

// Handles one onNewEvents payload: { events: [{ name, data }] }
export function applyEvents(state, payload) {
  for (const event of payload?.events ?? []) {
    if (event.name === 'match_start') resetState(state)
  }
}

// Builds one row per champion: how many copies are out of the pool and who holds them.
export function computePool(state, champions, poolSizes) {
  const byApiName = new Map(champions.map((c) => [c.apiName.toLowerCase(), c]))
  const rows = new Map(
    champions.map((c) => [
      c.apiName,
      { ...c, total: poolSizes[c.cost] ?? 0, taken: 0, holders: [] },
    ]),
  )
  const unknown = new Set()

  const holdings = []
  holdings.push({ name: 'You', units: [...state.local.board, ...state.local.bench], seenStage: 'now' })
  for (const [key, p] of Object.entries(state.players)) {
    if (key === state.localKey || p.eliminated) continue
    holdings.push({ name: p.name, units: [...p.board, ...p.bench], seenStage: p.seenStage })
  }

  for (const holder of holdings) {
    const perChampion = new Map()
    for (const unit of holder.units) {
      const champ = byApiName.get(unit.name.toLowerCase())
      if (!champ) {
        unknown.add(unit.name)
        continue
      }
      perChampion.set(champ.apiName, (perChampion.get(champ.apiName) ?? 0) + copiesForStar(unit.level))
    }
    for (const [apiName, copies] of perChampion) {
      const row = rows.get(apiName)
      row.taken += copies
      row.holders.push({ player: holder.name, copies, seenStage: holder.seenStage })
    }
  }

  for (const row of rows.values()) {
    row.remaining = Math.max(0, row.total - row.taken)
  }

  return { rows: [...rows.values()], unknown: [...unknown] }
}
