<?php

namespace App\Services\Riot;

use App\Enums\Platform;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Thin wrapper around the Riot API endpoints TFT Enhancer uses.
 *
 * By default it only waits a few seconds for rate limits and otherwise
 * throws, so web requests stay fast. Background jobs call
 * waitingOnRateLimits() to sleep as long as needed and never give up.
 *
 * @see https://developer.riotgames.com/apis
 */
class RiotClient
{
    /** Longest rate-limit wait (seconds) before a non-waiting client gives up. */
    public const MAX_SHORT_WAIT = 3.0;

    private const MAX_ATTEMPTS = 3;

    private bool $waitOnRateLimits = false;

    /** @var (Closure(float): void)|null */
    private ?Closure $onWait = null;

    public function __construct(
        private readonly ?string $apiKey,
        private readonly RateLimiter $limiter,
    ) {}

    /**
     * A copy of this client that sleeps through rate limits instead of failing.
     *
     * @param  (Closure(float): void)|null  $onWait  Called with the number of seconds before each wait.
     */
    public function waitingOnRateLimits(?Closure $onWait = null): static
    {
        $client = clone $this;
        $client->waitOnRateLimits = true;
        $client->onWait = $onWait;

        return $client;
    }

    /**
     * @return array{puuid: string, gameName: string, tagLine: string}|null
     */
    public function accountByRiotId(Platform $platform, string $gameName, string $tagLine): ?array
    {
        /** @var array{puuid: string, gameName: string, tagLine: string}|null */
        return $this->get(
            $platform->region(),
            'account',
            '/riot/account/v1/accounts/by-riot-id/'.rawurlencode($gameName).'/'.rawurlencode($tagLine),
        );
    }

    /**
     * Ranked entries (RANKED_TFT, double up, ...) for a player.
     *
     * @return list<array<string, mixed>>
     */
    public function leagueEntries(Platform $platform, string $puuid): array
    {
        /** @var list<array<string, mixed>> */
        return $this->get($platform->value, 'league', '/tft/league/v1/by-puuid/'.rawurlencode($puuid)) ?? [];
    }

    /**
     * @return array{entries: list<array<string, mixed>>}|null
     */
    public function challengerLeague(Platform $platform): ?array
    {
        /** @var array{entries: list<array<string, mixed>>}|null */
        return $this->get($platform->value, 'challenger', '/tft/league/v1/challenger');
    }

    /**
     * Most recent match ids, optionally only matches that started before $endTime.
     *
     * @return list<string>
     */
    public function matchIds(Platform $platform, string $puuid, int $count = 20, ?int $endTime = null): array
    {
        /** @var list<string> */
        return $this->get(
            $platform->region(),
            'match-ids',
            '/tft/match/v1/matches/by-puuid/'.rawurlencode($puuid).'/ids',
            array_filter(['count' => $count, 'endTime' => $endTime]),
        ) ?? [];
    }

    /**
     * @return array{metadata: array<string, mixed>, info: array<string, mixed>}|null
     */
    public function match(Platform $platform, string $matchId): ?array
    {
        /** @var array{metadata: array<string, mixed>, info: array<string, mixed>}|null */
        return $this->get($platform->region(), 'match', '/tft/match/v1/matches/'.rawurlencode($matchId));
    }

    /**
     * The TFT game a player is in right now, or null when they aren't in one.
     * Development keys get HTTP 403 here; Riot only opens it to approved products.
     *
     * @return array<string, mixed>|null
     */
    public function activeGame(Platform $platform, string $puuid): ?array
    {
        return $this->get($platform->value, 'active-game', '/lol/spectator/tft/v5/active-games/by-puuid/'.rawurlencode($puuid));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>|null Decoded JSON, or null when Riot returns 404.
     */
    private function get(string $host, string $method, string $path, array $query = []): ?array
    {
        if (blank($this->apiKey)) {
            throw new RiotApiException('RIOT_API_KEY is not configured.');
        }

        $attempt = 0;

        while (true) {
            while (($wait = $this->limiter->reserve($host, $method)) > 0) {
                $this->pause($wait);
            }

            try {
                $response = Http::baseUrl("https://{$host}.api.riotgames.com")
                    ->withHeaders(['X-Riot-Token' => $this->apiKey])
                    ->acceptJson()
                    ->timeout(10)
                    ->get($path, $query);
            } catch (ConnectionException $e) {
                if (++$attempt < self::MAX_ATTEMPTS) {
                    Sleep::for($attempt * 500)->milliseconds();

                    continue;
                }

                throw new RiotApiException("Could not reach the Riot API: {$e->getMessage()}", 0, $e);
            }

            $this->limiter->rememberLimits(
                $host,
                $method,
                $response->header('X-App-Rate-Limit') ?: null,
                $response->header('X-Method-Rate-Limit') ?: null,
            );

            if ($response->status() === 429) {
                // Our own count was off (e.g. another app using the key): back off as Riot asks.
                $this->pause(max(1, (int) $response->header('Retry-After')));

                continue;
            }

            if ($response->serverError() && ++$attempt < self::MAX_ATTEMPTS) {
                Sleep::for($attempt * 500)->milliseconds();

                continue;
            }

            if ($response->notFound()) {
                return null;
            }

            if ($response->failed()) {
                throw RiotApiException::fromStatus($response->status(), $path);
            }

            /** @var array<mixed> */
            return $response->json();
        }
    }

    private function pause(float $seconds): void
    {
        if (! $this->waitOnRateLimits && $seconds > self::MAX_SHORT_WAIT) {
            throw RiotApiException::rateLimited($seconds);
        }

        if ($this->onWait !== null) {
            ($this->onWait)($seconds);
        }

        Sleep::for((int) ceil($seconds * 1000))->milliseconds();
    }
}
