<?php

namespace Tests\Feature;

use App\Models\TftMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class CrawlMetaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.riot.key' => 'RGAPI-test']);
        Sleep::fake();
    }

    public function test_it_crawls_several_tiers_and_tags_matches_with_their_tier(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/tft/league/v1/challenger') => Http::response(['entries' => [
                    ['puuid' => 'chall-1', 'leaguePoints' => 1500],
                ]]),
                (bool) preg_match('#/entries/DIAMOND/([IV]+)#', $url, $d) => Http::response([
                    ['puuid' => "dia-{$d[1]}", 'inactive' => false],
                    ['puuid' => 'dia-inactive', 'inactive' => true],
                ]),
                (bool) preg_match('#/by-puuid/([^/]+)/ids#', $url, $m) => Http::response(["M_{$m[1]}"]),
                (bool) preg_match('#/matches/(M_[^/?]+)$#', $url, $m) => Http::response($this->match($m[1])),
                default => Http::response([], 404),
            };
        });

        $this->artisan('tft:crawl-meta', ['--tier' => ['challenger', 'diamond'], '--players' => 4, '--matches' => 1])
            ->assertSuccessful();

        $this->assertSame('CHALLENGER', TftMatch::where('match_id', 'M_chall-1')->value('sample_tier'));
        // Diamond players are spread over divisions I-IV; inactive ones are skipped.
        $diamond = TftMatch::where('sample_tier', 'DIAMOND')->pluck('match_id')->all();
        $this->assertCount(4, $diamond);
        $this->assertNotContains('M_dia-inactive', $diamond);
    }

    public function test_unknown_tiers_are_rejected(): void
    {
        $this->artisan('tft:crawl-meta', ['--tier' => ['legendary']])->assertFailed();
    }

    /**
     * @return array<string, mixed>
     */
    private function match(string $id): array
    {
        return [
            'metadata' => ['match_id' => $id],
            'info' => [
                'game_datetime' => now()->getTimestampMs(),
                'game_length' => 2000,
                'game_version' => 'Version 16.19',
                'tft_set_number' => 18,
                'participants' => [[
                    'puuid' => 'someone', 'placement' => 1, 'level' => 8, 'last_round' => 30,
                    'traits' => [], 'units' => [],
                ]],
            ],
        ];
    }
}
