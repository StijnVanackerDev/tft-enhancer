# TFT Enhancer – overlay

An Overwolf app (Vue 3 + Vite) that shows how many copies of each champion are
out of the shared TFT pool, based on your own board/bench and the opponents'
boards you scout.

## Commands

| Command | What it does |
|---|---|
| `npm install` | Install dependencies |
| `npm run dev` | Browser preview with a simulated lobby (no game needed) |
| `npm run build` | Build the Overwolf app into `dist/` |
| `npm test` | Unit tests for the tracking logic |
| `npm run fetch-data [-- <set>]` | Refresh `src/data/champions.json` from Community Dragon |
| `npm run icons` | Regenerate the placeholder icons |

## Running it in the game

1. Install [Overwolf](https://www.overwolf.com/) and request developer access
   for your account (see the [Overwolf dev docs](https://dev.overwolf.com/ow-native/getting-started/onboarding-resources/)).
2. `npm run build`
3. Open Overwolf → Settings → About → Development options → **Load unpacked
   extension** and pick the `dist/` folder.
4. Start a TFT game. The overlay opens automatically; toggle it with
   **Ctrl+Shift+P**.
5. After a rebuild, click **Reload** in the development options window.

Debugging: in the development options window, open the devtools for the
`background` window and inspect `tftBridge.debugLog` to see the raw events
Overwolf sends.

## How it works

- `src/background/main.js` detects TFT, subscribes to Overwolf's
  [TFT game events](https://dev.overwolf.com/ow-native/live-game-data-gep/supported-games/teamfight-tactics)
  and keeps the state. The overlay reads it through `window.tftBridge`.
- `src/lib/tracker.js` is the pure logic: parsing events into per-player units
  and computing the pool. Units count as 1/3/9 copies for 1★/2★/3★.
- `src/in_game/` is the Vue overlay.

## Things to check / known limits

- **Pool sizes** per cost live in `src/lib/poolSizes.js`. Verify them for the
  current set.
- **Set**: `fetch-data` picks the newest set on Community Dragon, which can be
  the PBE set. Run `npm run fetch-data -- <live set number>` if needed.
- **Game id**: the manifest uses `21570`, as documented by Overwolf for TFT.
  If the app doesn't start with the game, try adding `5426` (League).
- Opponents' benches are only known when Overwolf sends `board_players`;
  otherwise only their boards (when you scout them) are counted.
- Units an opponent sold after you last scouted them are still counted. The
  "seen" stage next to each player shows how fresh that data is.
- Augment data is deliberately not used: Overwolf states that showing it
  violates Riot's terms.
