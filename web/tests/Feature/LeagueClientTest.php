<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeLobby;
use App\Models\LobbyAnalysis;
use App\Models\Player;
use App\Services\Riot\LeagueClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

class LeagueClientTest extends TestCase
{
    use RefreshDatabase;

    private string $lockfile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lockfile = storage_path('framework/testing/lockfile');
        @mkdir(dirname($this->lockfile), 0777, true);
        file_put_contents($this->lockfile, 'LeagueClient:1234:54321:secret:https');

        config(['services.league_client.lockfiles' => [$this->lockfile], 'services.riot.key' => 'RGAPI-test']);
        $this->app->forgetInstance(LeagueClient::class);
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        @unlink($this->lockfile);

        parent::tearDown();
    }

    public function test_it_reads_the_players_of_the_game_in_progress(): void
    {
        $this->fakeClient('InProgress');

        $game = app(LeagueClient::class)->currentGame();

        $this->assertSame('777', $game['gameId']);
        $this->assertSame('me', $game['self']['puuid']);
        $this->assertSame(['me', 'p1', 'p2'], array_column($game['players'], 'puuid'));
        $this->assertSame('One', $game['players'][1]['gameName']);
        $this->assertNull($game['players'][2]['gameName']);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Basic '.base64_encode('riot:secret')));
    }

    public function test_it_explains_when_there_is_no_game_or_no_client(): void
    {
        $this->fakeClient('Lobby');

        try {
            app(LeagueClient::class)->currentGame();
            $this->fail('Expected an exception.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not in a game', $e->getMessage());
        }

        unlink($this->lockfile);
        $this->expectExceptionMessage('not running');
        app(LeagueClient::class)->currentGame();
    }

    public function test_the_button_starts_an_analysis_of_the_current_game(): void
    {
        config(['features.lobby_analysis' => true]);
        Queue::fake();
        $this->fakeClient('InProgress');
        $me = Player::factory()->create(['puuid' => 'me']);
        $someoneElse = Player::factory()->create(['puuid' => 'not-in-game']);

        // Only the player logged in to the client can load its game.
        $this->post(route('lobby.store', $someoneElse), ['source' => 'client'])
            ->assertSessionHasErrors('source');

        $this->post(route('lobby.store', $me), ['source' => 'client']);

        $analysis = LobbyAnalysis::sole();
        $this->assertSame('client', $analysis->source);
        $this->assertSame('777', $analysis->source_id);
        $this->assertSame(['me', 'p1', 'p2'], array_column($analysis->participants, 'puuid'));
        Queue::assertPushed(AnalyzeLobby::class);
    }

    public function test_the_job_looks_up_names_the_client_did_not_give(): void
    {
        config(['features.lobby_analysis' => true]);
        $this->fakeClient('InProgress', [
            'europe.api.riotgames.com/riot/account/v1/accounts/by-puuid/p2' => Http::response(['puuid' => 'p2', 'gameName' => 'Two', 'tagLine' => 'EUW']),
            '*.api.riotgames.com/*' => Http::response([]),
        ]);
        $me = Player::factory()->create(['puuid' => 'me']);

        $this->post(route('lobby.store', $me), ['source' => 'client']);

        $analysis = LobbyAnalysis::sole()->fresh();
        $this->assertSame('done', $analysis->status, (string) $analysis->message);
        $this->assertSame('Two', collect($analysis->participants)->firstWhere('puuid', 'p2')['gameName']);
    }

    /**
     * @param  array<string, mixed>  $extra  More faked URLs (e.g. the Riot API).
     */
    private function fakeClient(string $phase, array $extra = []): void
    {
        Http::fake([
            'https://127.0.0.1:54321/lol-gameflow/v1/session' => Http::response([
                'phase' => $phase,
                'gameData' => [
                    'gameId' => 777,
                    'teamOne' => [
                        ['puuid' => 'me', 'gameName' => 'Myself', 'tagLine' => 'EUW'],
                        ['puuid' => 'p1', 'gameName' => 'One', 'tagLine' => 'EUW'],
                        ['puuid' => 'p2'],
                    ],
                    'teamTwo' => [],
                ],
            ]),
            'https://127.0.0.1:54321/lol-summoner/v1/current-summoner' => Http::response([
                'puuid' => 'me', 'gameName' => 'Myself', 'tagLine' => 'EUW',
            ]),
            ...$extra,
        ]);
    }
}
