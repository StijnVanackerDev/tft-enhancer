<?php

namespace App\Services\Riot;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;

/**
 * Client-side copy of Riot's rate limits, so we wait before Riot says no.
 *
 * Riot enforces an "app" limit per routing host (e.g. 20 per second and
 * 100 per 2 minutes for a development key) and a separate limit per
 * endpoint ("method"). The real limits are read from the X-App-Rate-Limit and
 * X-Method-Rate-Limit response headers; until then the configured defaults
 * are used. Request timestamps live in the cache, so all PHP processes
 * (web requests and queue workers) share one budget.
 */
class RateLimiter
{
    private const PREFIX = 'riot-rate-limit:';

    /**
     * @param  list<array{int, int}>  $defaultAppLimits  [[requests, seconds], ...]
     */
    public function __construct(
        private readonly Repository $cache,
        private readonly array $defaultAppLimits,
    ) {}

    /**
     * Reserve a slot for one request. Returns 0 when the request may be sent
     * now (the slot is then taken), otherwise the seconds to wait first.
     */
    public function reserve(string $host, string $method): float
    {
        $store = $this->cache->getStore();

        // Serialise reservations across processes when the cache supports locks.
        return $store instanceof LockProvider
            ? (float) $store->lock(self::PREFIX.'lock', 10)->block(10, fn () => $this->take($host, $method))
            : $this->take($host, $method);
    }

    /**
     * Remember the limits Riot reports, e.g. "20:1,100:120".
     */
    public function rememberLimits(string $host, string $method, ?string $appHeader, ?string $methodHeader): void
    {
        foreach (["app:{$host}" => $appHeader, "method:{$host}:{$method}" => $methodHeader] as $bucket => $header) {
            $limits = self::parse($header);

            if ($limits !== []) {
                $this->cache->put(self::PREFIX."limits:{$bucket}", $limits, now()->addDay());
            }
        }
    }

    /**
     * "20:1,100:120" -> [[20, 1], [100, 120]]
     *
     * @return list<array{int, int}>
     */
    public static function parse(?string $header): array
    {
        if (blank($header)) {
            return [];
        }

        $limits = [];

        foreach (explode(',', $header) as $pair) {
            [$max, $seconds] = array_pad(explode(':', trim($pair)), 2, null);

            if (is_numeric($max) && is_numeric($seconds) && (int) $seconds > 0) {
                $limits[] = [(int) $max, (int) $seconds];
            }
        }

        return $limits;
    }

    private function take(string $host, string $method): float
    {
        $now = now()->getTimestampMs() / 1000;
        $wait = 0.0;
        $buckets = [
            "app:{$host}" => $this->limits("app:{$host}") ?? $this->defaultAppLimits,
            "method:{$host}:{$method}" => $this->limits("method:{$host}:{$method}") ?? [],
        ];
        $histories = [];

        foreach ($buckets as $bucket => $limits) {
            $longest = max([0, ...array_column($limits, 1)]);
            $history = array_values(array_filter($this->history($bucket), fn (float $t) => $t > $now - $longest));
            $histories[$bucket] = $history;

            foreach ($limits as [$max, $seconds]) {
                $inWindow = array_values(array_filter($history, fn (float $t) => $t > $now - $seconds));
                $over = count($inWindow) - $max;

                if ($over >= 0) {
                    // Wait until enough old requests have left the window.
                    $wait = max($wait, $inWindow[$over] + $seconds - $now);
                }
            }
        }

        if ($wait > 0) {
            return $wait;
        }

        foreach ($histories as $bucket => $history) {
            $history[] = $now;
            $this->cache->put(self::PREFIX."history:{$bucket}", $history, now()->addHour());
        }

        return 0.0;
    }

    /**
     * @return list<array{int, int}>|null
     */
    private function limits(string $bucket): ?array
    {
        $stored = $this->cache->get(self::PREFIX."limits:{$bucket}");

        if (! is_array($stored)) {
            return null;
        }

        $limits = [];
        foreach ($stored as $limit) {
            if (is_array($limit) && is_int($limit[0] ?? null) && is_int($limit[1] ?? null)) {
                $limits[] = [$limit[0], $limit[1]];
            }
        }

        return $limits;
    }

    /**
     * @return list<float>
     */
    private function history(string $bucket): array
    {
        $stored = $this->cache->get(self::PREFIX."history:{$bucket}", []);

        return is_array($stored) ? array_values(array_map(floatval(...), $stored)) : [];
    }
}
