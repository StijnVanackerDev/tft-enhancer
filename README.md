# TFT Enhancer

Tools for Teamfight Tactics players.

| Folder | What | Stack |
|---|---|---|
| `web/` | Website: match history and personal stats via the Riot API | Laravel 13, Inertia, Vue 3 |
| `overlay/` | Overwolf in-game overlay (prototype) | Vue 3, Vite |

## Web: local development

Requires PHP 8.4 and Node 22+ (e.g. via [Laravel Herd](https://herd.laravel.com)).

```bash
cd web
composer install && npm install
cp .env.example .env && php artisan key:generate
# put your Riot key in .env: RIOT_API_KEY=RGAPI-...
php artisan migrate
php artisan tft:import-static     # champion/trait/item names and icons
composer run dev                  # http://localhost:8000
```

No working Riot key? `php artisan db:seed --class=DemoSeeder` and open
`/players/euw1/Demo Player-DEMO`.

## Web: comps, playstyle and lobby analysis

- **Comp definitions** (names, units, traits, levelling) are imported from a
  manually exported comps JSON file. After each patch:
  1. Save the `comps_data` JSON response as
     `web/storage/comps-import/comps_data1.json` (this folder is gitignored).
  2. Run `php artisan tft:import-comps`. It replaces the definitions of that
     set. For the live site, run it once against the production database
     (`DB_CONNECTION=pgsql DB_URL=... php artisan tft:import-comps`).

  Boards are matched to the definition whose units they contain most of (at
  least half); everything else counts as a situational board. All statistics
  (games, placement, top 4) are computed from our own match data. Without
  definitions for a set, comps are derived from the boards themselves.
- **Comps page** (`/comps`): the imported comps with our own stats for the
  current set. Import high-elo games with
  `php artisan tft:crawl-meta --players=10 --matches=10` (waits through rate
  limits, so it can take a while on a development key).
- **Playstyle** on each player page: leveling speed (level vs. the usual
  level of players knocked out in the same stage), reroll vs. 4/5-cost
  carries, flexibility and most played comps. Riot no longer fills in
  damage to players, so "tempo" is estimated from levels.
- **Lobby analysis** (behind `LOBBY_ANALYSIS_ENABLED`, off by default):
  loads rank and recent games of all 8 players of a lobby in a queued job
  and predicts contested champions, likely comps per player and open comps.
  Riot's TFT policy does not allow showing lobby/player aggregate stats
  during gameplay, so keep it off in production unless Riot approves it.
  Live games need the spectator endpoint, which development keys can't use;
  past matches work with any key. Locally, `composer run dev` runs the queue
  worker the analysis needs.

### How the prediction works

Comps come from the imported definitions (see above); open comps must have
at least 1% of the matched boards and an average placement of 4.4 or better.
Without definitions a comp is identified by its carry: the 1-4 cost unit
holding the most items (5-costs are capstones, not comps). Per
player, P(champion on board) blends their recent boards (weight 0.85 per
game back) with how common the champion is overall. The meta weight is
`2 + 16 x (distinct carries / games)`, so players who force comps are
predicted from their own history and flexible players mostly from the meta.

Backtested on 173 Challenger games (predicting each game from the player's
earlier games only):

| Approach | Top-5 champion hits | Brier score (lower is better) |
|---|---|---|
| Own history only | 28-30% | 0.112-0.119 |
| Meta only | 33.6% | 0.1015 |
| Blend with adaptive weight (used) | 35.3% | 0.1010 |

High-elo players rarely repeat a comp, so their history adds little over
the meta; the blend helps most for players who force comps.

## Web: deploying to Render (free)

1. Create a free Postgres database on [Neon](https://neon.tech) and copy its
   connection string.
2. Push this repository to GitHub.
3. In Render: **New → Blueprint**, pick the repository. `render.yaml` sets up
   the service. Fill in:
   - `APP_KEY`: output of `php artisan key:generate --show`
   - `APP_URL`: `https://<service-name>.onrender.com`
   - `DB_URL`: the Neon connection string
   - `RIOT_API_KEY`: your Riot key
4. Migrations run automatically when the container starts.

The free instance sleeps after ~15 minutes without traffic; the first visit
after that takes up to a minute.
