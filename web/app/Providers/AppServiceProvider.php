<?php

namespace App\Providers;

use App\Services\Riot\LeagueClient;
use App\Services\Riot\RateLimiter;
use App\Services\Riot\RiotClient;
use App\Services\Tft\CompClassifier;
use App\Services\Tft\StaticData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(RateLimiter::class, fn (): RateLimiter => new RateLimiter(
            Cache::store(),
            RateLimiter::parse(config('services.riot.rate_limits')),
        ));

        $this->app->singleton(RiotClient::class, fn (): RiotClient => new RiotClient(
            config('services.riot.key'),
            $this->app->make(RateLimiter::class),
        ));

        $this->app->singleton(LeagueClient::class, fn (): LeagueClient => new LeagueClient(
            config('services.league_client.lockfiles', []),
        ));

        $this->app->singleton(StaticData::class);
        // Caches the imported comp definitions for the whole request/job.
        $this->app->singleton(CompClassifier::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
