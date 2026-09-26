<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Jobs\AnalyzeLobby;
use App\Models\LobbyAnalysis;
use App\Models\Participant;
use App\Models\Player;
use App\Models\TftMatch;
use App\Services\Tft\LobbyPredictor;
use App\Services\Tft\Playstyle;
use App\Services\Tft\StaticData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LobbyAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private const TRAITS = ['TFT18_Blossom', 'TFT18_Coven', 'TFT18_Inferno', 'TFT18_Lunar'];

    private const CARRIES = ['TFT18_Ahri', 'TFT18_Cassiopeia', 'TFT18_Kennen', 'TFT18_Aphelios'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.riot.key' => 'RGAPI-test', 'services.riot.lobby_history' => 5]);
        Sleep::fake();

        Storage::fake('local');
        Storage::disk('local')->put(StaticData::FILE, json_encode([
            'champions' => [
                'TFT18_Ahri' => ['name' => 'Ahri', 'cost' => 4, 'icon' => null],
                'TFT18_Cassiopeia' => ['name' => 'Cassiopeia', 'cost' => 3, 'icon' => null],
                'TFT18_Kennen' => ['name' => 'Kennen', 'cost' => 5, 'icon' => null],
                'TFT18_Aphelios' => ['name' => 'Aphelios', 'cost' => 4, 'icon' => null],
                'TFT18_Leona' => ['name' => 'Leona', 'cost' => 1, 'icon' => null],
            ],
            'traits' => [],
            'items' => [],
        ]));
    }

    public function test_it_is_hidden_when_the_feature_flag_is_off(): void
    {
        config(['features.lobby_analysis' => false]);
        $player = Player::factory()->create();

        $this->post(route('lobby.store', $player), ['source' => 'match', 'match_id' => 'X'])->assertNotFound();
    }

    public function test_starting_an_analysis_for_a_past_match_queues_the_job(): void
    {
        config(['features.lobby_analysis' => true]);
        Queue::fake();
        [$player, $match] = $this->lobbyMatch();

        $response = $this->post(route('lobby.store', $player), ['source' => 'match', 'match_id' => $match->match_id]);

        $analysis = LobbyAnalysis::sole();
        $response->assertRedirect(route('lobby.show', $analysis));
        $this->assertCount(8, $analysis->participants);
        $this->assertSame(18, $analysis->set_number);
        Queue::assertPushed(AnalyzeLobby::class);

        // Asking again reuses the running analysis.
        $this->post(route('lobby.store', $player), ['source' => 'match', 'match_id' => $match->match_id]);
        $this->assertSame(1, LobbyAnalysis::count());
    }

    public function test_an_analysed_match_links_to_its_analysis_instead_of_starting_a_new_one(): void
    {
        config(['features.lobby_analysis' => true]);
        Queue::fake();
        [$player, $match] = $this->lobbyMatch();
        $player->update(['synced_at' => now()]);

        $this->post(route('lobby.store', $player), ['source' => 'match', 'match_id' => $match->match_id]);
        $analysis = LobbyAnalysis::sole();
        $analysis->update(['status' => 'done']);

        // Days later, the same match still reuses the finished analysis.
        $this->travel(3)->days();
        $this->post(route('lobby.store', $player), ['source' => 'match', 'match_id' => $match->match_id])
            ->assertRedirect(route('lobby.show', $analysis));
        $this->assertSame(1, LobbyAnalysis::count());

        $player->update(['synced_at' => now()]);
        $this->get(route('players.show', [$player->platform, $player->slug]))
            ->assertInertia(fn (Assert $page) => $page->where('matches.0.lobbyAnalysisId', $analysis->id));
    }

    public function test_the_job_loads_every_players_history_and_predicts_the_lobby(): void
    {
        config(['features.lobby_analysis' => true]);
        [$player, $match] = $this->lobbyMatch();

        // Every lobby member has 3 older matches in which they always play
        // their own comp (player i -> trait i % 4, carry i % 4).
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/tft/league/v1/by-puuid/')) {
                return Http::response([['queueType' => 'RANKED_TFT', 'tier' => 'DIAMOND', 'rank' => 'I', 'leaguePoints' => 50]]);
            }

            if (preg_match('#/by-puuid/p(\d)/ids#', $url, $m)) {
                return Http::response(["OLD_{$m[1]}_1", "OLD_{$m[1]}_2", "OLD_{$m[1]}_3"]);
            }

            if (preg_match('#/matches/OLD_(\d)_(\d)$#', $url, $m)) {
                return Http::response($this->matchPayload("OLD_{$m[1]}_{$m[2]}", (int) $m[1]));
            }

            return Http::response([], 404);
        });

        $this->post(route('lobby.store', $player), ['source' => 'match', 'match_id' => $match->match_id]);

        $analysis = LobbyAnalysis::sole()->fresh();
        $this->assertSame('done', $analysis->status, (string) $analysis->message);
        $this->assertSame($analysis->progress_total, $analysis->progress_done);
        // 8 x (rank + match list) + 24 matches.
        $this->assertSame(40, $analysis->progress_total);

        $result = $analysis->result;
        $this->assertCount(8, $result['players']);
        $this->assertTrue($result['players'][0]['isSubject']);
        $this->assertSame('DIAMOND', $result['players'][1]['rank']['tier']);
        $this->assertNotEmpty($result['contested']);

        // Player 1 always played Cassiopeia and is the only other one forcing it.
        $second = $result['players'][1];
        $this->assertSame('TFT18_Cassiopeia', $second['likelyComps'][0]['key']);
        // Only 3 games of history: confident, but still blended with the meta.
        $this->assertGreaterThan(0.4, $second['likelyComps'][0]['probability']);
        $this->assertLessThan(0.6, $second['likelyComps'][0]['probability']);

        $this->get(route('lobby.show', $analysis))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('lobby/Show')
                ->where('analysis.status', 'done')
                ->has('analysis.result.players', 8));
    }

    public function test_one_tricks_are_predicted_with_more_confidence_than_flexible_players(): void
    {
        $predictor = app(LobbyPredictor::class);

        $forcer = collect(range(1, 6))->map(fn () => $this->board(0));
        $flexible = collect(range(0, 5))->map(fn (int $i) => $this->board($i % 4));

        $forcerTop = max($predictor->compDistribution($forcer, []));
        $flexibleTop = max($predictor->compDistribution($flexible, []));

        $this->assertGreaterThan(0.9, $forcerTop);
        $this->assertLessThan(0.4, $flexibleTop);
    }

    public function test_playstyle_tags_rerollers_and_one_tricks(): void
    {
        $games = collect(range(1, 5))->map(fn () => new Participant([
            'placement' => 3, 'level' => 7, 'last_round' => 30,
            'traits' => [['name' => 'TFT18_Blossom', 'num_units' => 4, 'style' => 2, 'tier_current' => 2, 'tier_total' => 3]],
            'units' => [['character_id' => 'TFT18_Cassiopeia', 'tier' => 3, 'rarity' => 2, 'items' => ['a', 'b', 'c']]],
        ]));

        $labels = array_column(app(Playstyle::class)->profile($games)['tags'], 'label');

        $this->assertContains('Reroll', $labels);
        $this->assertContains('One-trick', $labels);
        $this->assertContains('Slow roll', $labels);
    }

    /**
     * A finished match with the searched player (p0) and 7 others (p1..p7).
     *
     * @return array{Player, TftMatch}
     */
    private function lobbyMatch(): array
    {
        $player = Player::factory()->create(['puuid' => 'p0']);
        $match = TftMatch::create([
            'match_id' => 'EUW1_LOBBY', 'platform' => Platform::EUW, 'played_at' => now(),
            'game_length' => 2000, 'game_version' => 'Version 16.19', 'set_number' => 18,
        ]);

        foreach (range(0, 7) as $i) {
            $match->participants()->create([
                'puuid' => "p{$i}", 'game_name' => "Player{$i}", 'tag_line' => 'EUW',
                'placement' => $i + 1, 'level' => 8, 'gold_left' => 0, 'last_round' => 30,
                ...$this->boardData($i % 4),
            ]);
        }

        return [$player, $match];
    }

    private function board(int $comp): Participant
    {
        return new Participant(['placement' => 4, 'level' => 8, 'last_round' => 30, ...$this->boardData($comp)]);
    }

    /**
     * @return array{traits: list<array<string, mixed>>, units: list<array<string, mixed>>}
     */
    private function boardData(int $comp): array
    {
        return [
            'traits' => [['name' => self::TRAITS[$comp], 'num_units' => 4, 'style' => 2, 'tier_current' => 2, 'tier_total' => 3]],
            'units' => [
                ['character_id' => self::CARRIES[$comp], 'tier' => 2, 'rarity' => 3, 'items' => ['a', 'b', 'c']],
                ['character_id' => 'TFT18_Leona', 'tier' => 2, 'rarity' => 0, 'items' => []],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function matchPayload(string $matchId, int $player): array
    {
        $board = $this->boardData($player % 4);

        return [
            'metadata' => ['match_id' => $matchId],
            'info' => [
                'game_datetime' => now()->subDays(1)->getTimestampMs(),
                'game_length' => 2000,
                'game_version' => 'Version 16.19',
                'tft_set_number' => 18,
                'participants' => [[
                    'puuid' => "p{$player}",
                    'placement' => 2,
                    'level' => 8,
                    'last_round' => 30,
                    'traits' => $board['traits'],
                    'units' => array_map(fn (array $u) => [...$u, 'itemNames' => $u['items']], $board['units']),
                ]],
            ],
        ];
    }
}
