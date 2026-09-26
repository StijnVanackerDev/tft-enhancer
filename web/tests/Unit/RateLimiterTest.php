<?php

namespace Tests\Unit;

use App\Services\Riot\RateLimiter;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;

class RateLimiterTest extends TestCase
{
    public function test_parses_riot_rate_limit_headers(): void
    {
        $this->assertSame([[20, 1], [100, 120]], RateLimiter::parse('20:1,100:120'));
        $this->assertSame([], RateLimiter::parse(null));
        $this->assertSame([[500, 10]], RateLimiter::parse('500:10,garbage'));
    }

    public function test_allows_requests_until_the_limit_then_asks_to_wait(): void
    {
        $limiter = new RateLimiter(new Repository(new ArrayStore), [[3, 60]]);

        $this->assertSame(0.0, $limiter->reserve('europe', 'match'));
        $this->assertSame(0.0, $limiter->reserve('europe', 'match'));
        $this->assertSame(0.0, $limiter->reserve('europe', 'match'));

        $wait = $limiter->reserve('europe', 'match');
        $this->assertGreaterThan(59, $wait);
        $this->assertLessThanOrEqual(60, $wait);
    }

    public function test_app_limits_are_counted_per_routing_host(): void
    {
        $limiter = new RateLimiter(new Repository(new ArrayStore), [[1, 60]]);

        $this->assertSame(0.0, $limiter->reserve('europe', 'match'));
        $this->assertSame(0.0, $limiter->reserve('euw1', 'league'));
        $this->assertGreaterThan(0, $limiter->reserve('europe', 'account'));
    }

    public function test_limits_reported_by_riot_replace_the_defaults(): void
    {
        $limiter = new RateLimiter(new Repository(new ArrayStore), [[100, 60]]);
        $limiter->rememberLimits('europe', 'match', '100:60', '1:10');

        $this->assertSame(0.0, $limiter->reserve('europe', 'match'));
        // The method limit (1 per 10s) now applies to this endpoint only.
        $this->assertGreaterThan(9, $limiter->reserve('europe', 'match'));
        $this->assertSame(0.0, $limiter->reserve('europe', 'match-ids'));
    }
}
