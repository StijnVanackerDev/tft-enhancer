<?php

namespace App\Services\Riot;

use App\Enums\Platform;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Thin wrapper around the Riot API endpoints TFT Enhancer uses.
 *
 * @see https://developer.riotgames.com/apis
 */
class RiotClient
{
    public function __construct(private readonly ?string $apiKey) {}

    /**
     * @return array{puuid: string, gameName: string, tagLine: string}|null
     */
    public function accountByRiotId(Platform $platform, string $gameName, string $tagLine): ?array
    {
        /** @var array{puuid: string, gameName: string, tagLine: string}|null */
        return $this->get(
            $platform->region(),
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
        return $this->get($platform->value, '/tft/league/v1/by-puuid/'.rawurlencode($puuid)) ?? [];
    }

    /**
     * @return list<string>
     */
    public function matchIds(Platform $platform, string $puuid, int $count = 20): array
    {
        /** @var list<string> */
        return $this->get(
            $platform->region(),
            '/tft/match/v1/matches/by-puuid/'.rawurlencode($puuid).'/ids',
            ['count' => $count],
        ) ?? [];
    }

    /**
     * @return array{metadata: array<string, mixed>, info: array<string, mixed>}|null
     */
    public function match(Platform $platform, string $matchId): ?array
    {
        /** @var array{metadata: array<string, mixed>, info: array<string, mixed>}|null */
        return $this->get($platform->region(), '/tft/match/v1/matches/'.rawurlencode($matchId));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>|null Decoded JSON, or null when Riot returns 404.
     */
    private function get(string $host, string $path, array $query = []): ?array
    {
        if (blank($this->apiKey)) {
            throw new RiotApiException('RIOT_API_KEY is not configured.');
        }

        try {
            $response = Http::baseUrl("https://{$host}.api.riotgames.com")
                ->withHeaders(['X-Riot-Token' => $this->apiKey])
                ->acceptJson()
                ->timeout(10)
                ->retry(
                    3,
                    fn (int $attempt, Throwable $e) => $this->retryDelay($attempt, $e),
                    fn (Throwable $e) => $this->shouldRetry($e),
                    throw: false,
                )
                ->get($path, $query);
        } catch (ConnectionException $e) {
            throw new RiotApiException("Could not reach the Riot API: {$e->getMessage()}", 0, $e);
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

    private function shouldRetry(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        return $e instanceof RequestException
            && in_array($e->response->status(), [429, 500, 502, 503, 504], true);
    }

    /**
     * Honour Riot's Retry-After header on 429s (capped, so a request never
     * hangs for minutes), otherwise back off linearly.
     */
    private function retryDelay(int $attempt, Throwable $e): int
    {
        $response = $e instanceof RequestException ? $e->response : null;

        if ($response instanceof Response && $response->status() === 429) {
            return min(max((int) $response->header('Retry-After'), 1), 10) * 1000;
        }

        return $attempt * 500;
    }
}
