// Background window: always running while the app is open. It detects TFT,
// listens to Overwolf game events, keeps the tracker state, and opens/closes
// the in-game overlay. The overlay reads state through window.tftBridge.
import { createState, applyInfoUpdate, applyEvents } from '../lib/tracker.js'

// TFT shares League's launcher/client; Overwolf reports either id.
const TFT_CLASS_IDS = [21570, 5426]
const IN_GAME_WINDOW = 'in_game'

// Augments are deliberately excluded: Overwolf states that showing augment data
// in third-party apps violates Riot's terms.
const REQUIRED_FEATURES = [
  'gep_internal',
  'game_info',
  'live_client_data',
  'me',
  'match_info',
  'roster',
  'store',
  'board',
  'bench',
  'match_stats',
]

const state = createState()
const listeners = new Set()
const debugLog = []

function notify() {
  const snapshot = JSON.parse(JSON.stringify(state))
  for (const fn of listeners) {
    try {
      fn(snapshot)
    } catch {
      // Listener belonged to a closed overlay window.
      listeners.delete(fn)
    }
  }
}

function log(entry) {
  debugLog.push({ at: new Date().toISOString(), ...entry })
  if (debugLog.length > 200) debugLog.shift()
}

window.tftBridge = {
  getState: () => JSON.parse(JSON.stringify(state)),
  subscribe(fn) {
    listeners.add(fn)
    return () => listeners.delete(fn)
  },
  debugLog,
}

function isTft(gameInfo) {
  return gameInfo && TFT_CLASS_IDS.includes(gameInfo.classId)
}

function setRequiredFeatures(attempt = 1) {
  overwolf.games.events.setRequiredFeatures(REQUIRED_FEATURES, (result) => {
    if (result.success) {
      console.log('GEP features registered:', result.supportedFeatures)
      overwolf.games.events.getInfo((info) => {
        if (info.success && info.res) {
          applyInfoUpdate(state, { info: info.res })
          notify()
        }
      })
      return
    }
    // GEP is often not ready right after the game starts; keep retrying.
    if (attempt < 20) setTimeout(() => setRequiredFeatures(attempt + 1), 3000)
    else console.error('Could not register GEP features', result)
  })
}

function onInfoUpdate(update) {
  log({ type: 'info', feature: update.feature, info: update.info })
  applyInfoUpdate(state, update)
  notify()
}

function onNewEvents(payload) {
  log({ type: 'events', events: payload.events })
  applyEvents(state, payload)
  notify()
}

function registerGameEvents() {
  overwolf.games.events.onInfoUpdates2.removeListener(onInfoUpdate)
  overwolf.games.events.onNewEvents.removeListener(onNewEvents)
  overwolf.games.events.onInfoUpdates2.addListener(onInfoUpdate)
  overwolf.games.events.onNewEvents.addListener(onNewEvents)
  overwolf.games.events.onError.addListener((e) => console.error('GEP error', e))
  setRequiredFeatures()
}

function withOverlay(fn) {
  overwolf.windows.obtainDeclaredWindow(IN_GAME_WINDOW, (result) => {
    if (result.success) fn(result.window)
  })
}

function showOverlay() {
  withOverlay((w) => overwolf.windows.restore(w.id))
}

function closeOverlay() {
  withOverlay((w) => overwolf.windows.close(w.id))
}

function toggleOverlay() {
  withOverlay((w) => {
    overwolf.windows.getWindowState(w.id, (s) => {
      if (s.window_state === 'normal' || s.window_state === 'maximized') {
        overwolf.windows.minimize(w.id)
      } else {
        overwolf.windows.restore(w.id)
      }
    })
  })
}

function onGameStarted() {
  registerGameEvents()
  showOverlay()
}

overwolf.games.onGameInfoUpdated.addListener((event) => {
  const info = event?.gameInfo
  if (!isTft(info)) return
  if (event.runningChanged || event.gameChanged) {
    if (info.isRunning) onGameStarted()
    else closeOverlay()
  }
})

overwolf.settings.hotkeys.onPressed.addListener((event) => {
  if (event.name === 'toggle_overlay') toggleOverlay()
})

// The app may be started while TFT is already running.
overwolf.games.getRunningGameInfo((info) => {
  if (info?.isRunning && isTft(info)) onGameStarted()
})
