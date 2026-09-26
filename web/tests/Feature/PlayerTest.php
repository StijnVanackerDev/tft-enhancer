<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Jobs\SyncPlayerMatches;
use App\Models\Participant;
use App\Models\Player;
use App\Models\TftMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlayerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.riot.key' => 'RGAPI-test']);
    }

    public function test_lookup_finds_account_and_redirects_to_player_page(): void
    {
        Bus::fake();
        Http::fake([
            'europe.api.riotgames.com/riot/account/v1/accounts/by-riot-id/Some%20Player/EUW' => Http::response([
                'puuid' => 'puuid-1', 'gameName' => 'Some Player', 'tagLine' => 'EUW',
            ]),
        ]);

        $this->post(route('players.lookup'), ['riot_id' => 'Some Player#EUW', 'platform' => 'euw1'])
            ->assertRedirect(route('players.show', ['euw1', 'Some Player-EUW']));

        $this->assertDatabaseHas('players', ['puuid' => 'puuid-1', 'platform' => 'euw1', 'game_name' => 'Some Player']);
        Http::assertSent(fn ($request) => $request->hasHeader('X-Riot-Token', 'RGAPI-test'));
    }

    public function test_lookup_shows_error_for_unknown_riot_id(): void
    {
        Http::fake(['*' => Http::response(['status' => ['status_code' => 404]], 404)]);

        $this->post(route('players.lookup'), ['riot_id' => 'Nobody#000', 'platform' => 'euw1'])
            ->assertSessionHasErrors(['riot_id' => 'No player found with that Riot ID.']);
    }

    public function test_lookup_validates_riot_id_format(): void
    {
        $this->post(route('players.lookup'), ['riot_id' => 'NoTagHere', 'platform' => 'euw1'])
            ->assertSessionHasErrors('riot_id');
    }

    public function test_lookup_reports_an_invalid_api_key_without_crashing(): void
    {
        Http::fake(['*' => Http::response(['status' => ['message' => 'Unknown apikey']], 401)]);

        $this->post(route('players.lookup'), ['riot_id' => 'Some Player#EUW', 'platform' => 'euw1'])
            ->assertSessionHasErrors(['riot_id' => 'Could not reach the Riot API right now. Please try again later.']);
    }

    public function test_player_page_shows_stats_and_starts_a_sync_when_stale(): void
    {
        Bus::fake();
        $player = Player::factory()->create(['game_name' => 'Some Player', 'tag_line' => 'EUW']);
        $this->createGame($player, placement: 1, units: ['TFT17_Jinx', 'TFT17_Poppy']);
        $this->createGame($player, placement: 6, units: ['TFT17_Jinx']);

        $this->get(route('players.show', ['euw1', 'some player-euw']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('players/Show')
                ->where('player.gameName', 'Some Player')
                ->where('player.isSyncing', true)
                ->where('summary.games', 2)
                ->where('summary.avgPlacement', 3.5)
                ->where('summary.top4Rate', 50)
                ->where('units.0.id', 'TFT17_Jinx')
                ->where('units.0.games', 2)
                ->has('matches', 2)
                ->where('matches.0.placement', 1));

        Bus::assertDispatchedAfterResponse(SyncPlayerMatches::class);
    }

    public function test_recently_synced_player_is_not_synced_again(): void
    {
        Bus::fake();
        $player = Player::factory()->synced()->create();

        $this->get(route('players.show', [$player->platform, $player->slug]))->assertOk();

        Bus::assertNotDispatchedAfterResponse(SyncPlayerMatches::class);
    }

    public function test_sync_stores_rank_and_new_matches(): void
    {
        $player = Player::factory()->create(['puuid' => 'me']);
        TftMatch::create([
            'match_id' => 'EUW1_1', 'platform' => Platform::EUW, 'played_at' => now(),
            'game_length' => 1800, 'game_version' => 'Version 16.19',
        ]);

        Http::fake([
            'euw1.api.riotgames.com/tft/league/v1/by-puuid/me' => Http::response([
                ['queueType' => 'RANKED_TFT', 'tier' => 'GOLD', 'rank' => 'II', 'leaguePoints' => 40, 'wins' => 10, 'losses' => 30],
            ]),
            'europe.api.riotgames.com/tft/match/v1/matches/by-puuid/me/ids*' => Http::response(['EUW1_2', 'EUW1_1']),
            'europe.api.riotgames.com/tft/match/v1/matches/EUW1_2' => Http::response($this->matchPayload('EUW1_2', 'me')),
        ]);

        $player->claimSync();
        app()->call([new SyncPlayerMatches($player), 'handle']);

        $player->refresh();
        $this->assertFalse($player->isSyncing());
        $this->assertNotNull($player->synced_at);
        $this->assertNull($player->sync_error);
        $this->assertSame('GOLD', $player->league[0]['tier']);
        $this->assertDatabaseCount('tft_matches', 2);
        $this->assertDatabaseCount('participants', 2);
        $this->assertDatabaseHas('participants', ['puuid' => 'me', 'placement' => 3]);

        // The already known match was not fetched again.
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/matches/EUW1_1'));
    }

    public function test_practice_games_against_bots_are_stored_without_the_bots_and_not_counted(): void
    {
        $player = Player::factory()->create(['puuid' => 'me', 'game_name' => 'Me', 'tag_line' => 'EUW']);
        $payload = $this->matchPayload('EUW1_BOTS', 'me');
        // Riot gives every bot the same puuid.
        $bot = $payload['info']['participants'][1];
        $payload['info']['participants'][1] = [...$bot, 'puuid' => 'BOT'];
        $payload['info']['participants'][2] = [...$bot, 'puuid' => 'BOT', 'placement' => 7];

        Http::fake([
            '*/tft/league/v1/*' => Http::response([]),
            '*/ids*' => Http::response(['EUW1_BOTS']),
            '*/matches/EUW1_BOTS' => Http::response($payload),
        ]);

        $player->claimSync();
        app()->call([new SyncPlayerMatches($player), 'handle']);

        $this->assertNull($player->fresh()->sync_error);
        $this->assertDatabaseHas('tft_matches', ['match_id' => 'EUW1_BOTS', 'game_type' => 'bots']);
        $this->assertDatabaseCount('participants', 1);

        Bus::fake();
        $this->get(route('players.show', ['euw1', 'Me-EUW']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.games', 0)
                ->where('matches.0.queue', 'vs. Bots'));
    }

    public function test_failed_sync_is_released_and_error_is_stored(): void
    {
        $player = Player::factory()->create();
        Http::fake(['*' => Http::response([], 403)]);

        $player->claimSync();
        app()->call([new SyncPlayerMatches($player), 'handle']);

        $player->refresh();
        $this->assertFalse($player->isSyncing());
        $this->assertNull($player->synced_at);
        $this->assertStringContainsString('invalid, expired', $player->sync_error);
    }

    public function test_sync_cannot_be_claimed_twice(): void
    {
        $player = Player::factory()->create();

        $this->assertTrue($player->claimSync());
        $this->assertFalse($player->fresh()->claimSync());
    }

    /**
     * @param  list<string>  $units
     */
    private function createGame(Player $player, int $placement, array $units): Participant
    {
        $match = TftMatch::create([
            'match_id' => 'EUW1_'.fake()->unique()->numberBetween(1, 1_000_000),
            'platform' => Platform::EUW,
            'played_at' => now()->subMinutes(10 * $placement),
            'game_length' => 2000,
            'game_version' => 'Version 16.19.1',
            'queue_id' => 1100,
            'set_number' => 17,
        ]);

        return $match->participants()->create([
            'puuid' => $player->puuid,
            'placement' => $placement,
            'level' => 8,
            'gold_left' => 0,
            'last_round' => 30,
            'traits' => [['name' => 'TFT17_Anima', 'num_units' => 3, 'style' => 1, 'tier_current' => 1, 'tier_total' => 3]],
            'units' => array_map(fn (string $id) => ['character_id' => $id, 'tier' => 2, 'rarity' => 1, 'items' => []], $units),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function matchPayload(string $matchId, string $puuid): array
    {
        $participant = fn (string $id, int $placement) => [
            'puuid' => $id,
            'riotIdGameName' => $id,
            'riotIdTagline' => 'EUW',
            'placement' => $placement,
            'level' => 8,
            'gold_left' => 3,
            'last_round' => 32,
            'total_damage_to_players' => 90,
            'players_eliminated' => 1,
            'augments' => ['TFT_Augment_Something'],
            'traits' => [['name' => 'TFT17_Anima', 'num_units' => 4, 'style' => 2, 'tier_current' => 2, 'tier_total' => 3]],
            'units' => [['character_id' => 'TFT17_Jinx', 'itemNames' => ['TFT_Item_InfinityEdge'], 'rarity' => 1, 'tier' => 2]],
        ];

        return [
            'metadata' => ['match_id' => $matchId, 'participants' => [$puuid, 'other']],
            'info' => [
                'game_datetime' => 1_790_000_000_000,
                'game_length' => 2100.5,
                'game_version' => 'Version 16.19.123',
                'queue_id' => 1100,
                'tft_set_number' => 17,
                'tft_game_type' => 'standard',
                'participants' => [$participant($puuid, 3), $participant('other', 5)],
            ],
        ];
    }
}
