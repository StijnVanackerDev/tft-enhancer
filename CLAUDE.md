# TFT Enhancer

Tools for Teamfight Tactics players. Monorepo:

- `web/`: Laravel 13 + Inertia + Vue 3 (TypeScript) site: match history, personal stats, playstyle, comps, lobby analysis. Deployed to Render (free, Docker, Frankfurt) with Neon Postgres. See `render.yaml` and `web/Dockerfile`.
- `overlay/`: Overwolf in-game overlay prototype (Vue 3 + Vite), pool tracker. On hold until Overwolf/Riot approval.

The root `README.md` explains features and deployment in more detail.

## Environment (Windows)

- PHP 8.4, Composer and Node come from Laravel Herd. Old shells may still pick up XAMPP's PHP 8.1; if `php -v` shows 8.1, prefix commands with
  `export PATH="/c/Users/stijn/.config/herd/bin/php84:/c/Users/stijn/.config/herd/bin:$PATH"` (Git Bash).
- Local dev: `cd web && composer run dev` (server on :8000, Vite, and the queue worker that lobby analyses need).
- No Docker or `gh` CLI locally. Push with plain `git push` (HTTPS, Git Credential Manager).

## Checks before committing (run in `web/`)

```
php artisan test
vendor/bin/pint
vendor/bin/phpstan analyse --memory-limit=1G   # 1 known error in the starter kit's UserFactory
npm run check:fix && npm run types:check
```

Tests fake the Riot API (`Http::fake`) and sleeps (`Sleep::fake()`); follow that pattern.

## Recurring tasks

- **Import comp definitions** (after every patch):
  1. The user saves the exported `comps_data` JSON as `web/storage/comps-import/comps_data1.json` (folder is gitignored, never commit it).
  2. `php artisan tft:import-comps` (replaces that set's definitions).
  3. For production, run once against Neon: `DB_CONNECTION=pgsql DB_URL="<neon url>" php artisan tft:import-comps` (ask the user for the URL; don't store it in files).
  Only names/units/traits/levelling are imported; all stats come from our own Riot data. Never call the source site's internal API directly: that was blocked by the safety check; the manual export is the agreed workflow.
- **Champion names/icons**: `php artisan tft:import-static` (Community Dragon). The Docker build runs it automatically.
- **High-elo matches for comp stats**: `php artisan tft:crawl-meta --players=10 --matches=10` (sleeps through rate limits).
- **Riot dev key** expires every 24h. It lives in `web/.env` as `RIOT_API_KEY` and in Render's env settings; update both. Test a key with account-v1 or tft-league-v1, not the status endpoints (those return 401 for dev keys even when valid).

## Rules and decisions

- **Riot policy**: no augment data anywhere (not stored, not shown), no Legend win rates. Lobby/player aggregate stats during gameplay are not allowed.
- **Lobby analysis** is behind `LOBBY_ANALYSIS_ENABLED` (true locally, must stay unset/false on Render). Don't make it live, and don't describe the product to Riot or Overwolf differently from what it does.
- Spectator (live game) endpoints return 403 for dev keys; only past-match lobby analysis works until there is a production key.
- Games vs bots (puuid `BOT`) are stored without the bots and excluded from all statistics (`TftMatch::againstPlayers()`).
- Riot API calls go through `App\Services\Riot\RiotClient` + `RateLimiter` (reads limits from response headers). Web requests fail fast on long rate limits; background jobs use `waitingOnRateLimits()` and never give up.
- Champion pool sizes per cost live in `web/config/tft.php` and `overlay/src/lib/poolSizes.js`; keep both in sync. Contest in the lobby analysis is measured in pool copies.
- Prediction weights were chosen by backtesting on real Challenger games (see README). Re-run a backtest before changing them.

## Status (keep up to date)

- Waiting on Riot production key approval (product URL = Render site, verified with `web/public/riot.txt`). Overwolf app idea is on hold until Riot approves.
