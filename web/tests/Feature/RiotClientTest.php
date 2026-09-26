<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Services\Riot\RateLimiter;
use App\Services\Riot\RiotApiException;
use App\Services\Riot\RiotClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class RiotClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.riot.key' => 'RGAPI-test']);
        Sleep::fake();
    }

    public function test_a_waiting_client_sleeps_through_a_429_and_finishes(): void
    {
        Http::fake([
            'europe.api.riotgames.com/*' => Http::sequence()
                ->push(['status' => ['status_code' => 429]], 429, ['Retry-After' => '30'])
                ->push(['EUW1_1']),
        ]);

        $waits = [];
        $riot = app(RiotClient::class)->waitingOnRateLimits(function (float $s) use (&$waits) {
            $waits[] = $s;
        });

        $this->assertSame(['EUW1_1'], $riot->matchIds(Platform::EUW, 'me'));
        $this->assertSame([30.0], $waits);
        Sleep::assertSleptTimes(1);
    }

    public function test_a_normal_client_gives_up_on_a_long_rate_limit(): void
    {
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '60'])]);

        $this->expectException(RiotApiException::class);
        $this->expectExceptionMessage('try again in 60 seconds');

        app(RiotClient::class)->matchIds(Platform::EUW, 'me');
    }

    public function test_a_waiting_client_waits_for_its_own_rate_limit_before_asking_riot(): void
    {
        config(['services.riot.rate_limits' => '2:120']);
        $this->app->forgetInstance(RateLimiter::class);
        $this->app->forgetInstance(RiotClient::class);

        Http::fake(['*' => Http::response(['EUW1_1'])]);
        $waits = 0;

        // Sleep is faked, so time doesn't pass on its own: the third call has
        // to wait, and travelling forward from the wait callback lets it through.
        $riot = app(RiotClient::class)->waitingOnRateLimits(function () use (&$waits) {
            $waits++;
            $this->travel(121)->seconds();
        });

        $riot->matchIds(Platform::EUW, 'a');
        $riot->matchIds(Platform::EUW, 'b');
        $riot->matchIds(Platform::EUW, 'c');

        $this->assertSame(1, $waits);
        Http::assertSentCount(3);
    }
}
