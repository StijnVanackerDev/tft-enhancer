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
