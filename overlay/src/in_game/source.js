// Where the overlay gets its state from:
//  - inside Overwolf: the background window's bridge
//  - in a normal browser (npm run dev): a simulated lobby, for UI work
import { startMockGame } from './mockSource.js'

export const isOverwolf = typeof window.overwolf !== 'undefined'

export function connect(onState) {
  if (!isOverwolf) return startMockGame(onState)

  const bridge = overwolf.windows.getMainWindow().tftBridge
  onState(bridge.getState())
  return bridge.subscribe(onState)
}

export function dragWindow() {
  if (!isOverwolf) return
  overwolf.windows.getCurrentWindow((r) => overwolf.windows.dragMove(r.window.id))
}

export function minimizeWindow() {
  if (!isOverwolf) return
  overwolf.windows.getCurrentWindow((r) => overwolf.windows.minimize(r.window.id))
}
