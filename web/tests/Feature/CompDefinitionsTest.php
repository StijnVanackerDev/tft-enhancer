<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\CompDefinition;
use App\Models\TftMatch;
use App\Services\Tft\CompClassifier;
use App\Services\Tft\LobbyPredictor;
use App\Services\Tft\MetaComps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CompDefinitionsTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = 'storage/framework/testing/comps_data.json';
        @mkdir(dirname(base_path($this->file)), 0777, true);
        file_put_contents(base_path($this->file), json_encode([
            'tft_set' => 'TFTSet18',
            'results' => ['data' => ['cluster_details' => [
                '1' => [
                    'Cluster' => 1,
                    'units_string' => 'U_A, U_B, U_C, U_D',
                    'traits_string' => 'T_Blossom_2, T_Invoker_1',
                    'name' => [['name' => 'T_Invoker', 'type' => 'trait'], ['name' => 'U_A', 'type' => 'unit']],
                    'levelling' => 'Fast 8',
                ],
                '2' => [
                    'Cluster' => 2,
                    'units_string' => 'U_E, U_F, U_G, U_H',
                    'traits_string' => 'T_Juggernaut_3',
                    'name' => [['name' => 'T_Juggernaut', 'type' => 'trait'], ['name' => 'U_E', 'type' => 'unit']],
                    'levelling' => 'lvl 6',
                ],
            ]]],
        ]));
    }

    protected function tearDown(): void
    {
        @unlink(base_path($this->file));

        parent::tearDown();
    }

    public function test_import_stores_definitions_and_replaces_the_previous_import(): void
    {
        $this->artisan('tft:import-comps', ['file' => $this->file])
            ->expectsOutputToContain('Imported 2 comps for Set 18.')
            ->assertSuccessful();

        $this->artisan('tft:import-comps', ['file' => $this->file])->assertSuccessful();

        $this->assertSame(2, CompDefinition::count());
        $comp = CompDefinition::where('external_id', '1')->sole();
        $this->assertSame(['U_A', 'U_B', 'U_C', 'U_D'], $comp->units);
        $this->assertSame(['U_A'], $comp->carries);
        $this->assertSame([['name' => 'T_Blossom', 'tier' => 2], ['name' => 'T_Invoker', 'tier' => 1]], $comp->traits);
        $this->assertSame('Fast 8', $comp->levelling);
    }

    public function test_boards_are_matched_to_the_closest_definition_or_marked_situational(): void
    {
        $this->artisan('tft:import-comps', ['file' => $this->file]);
        $classifier = app(CompClassifier::class);
        $invoker = CompDefinition::where('external_id', '1')->sole();

        // 3 of the 4 Invoker units, plus one of the other comp.
        $key = $classifier->classify([], $this->units(['U_A', 'U_B', 'U_C', 'U_E']))['key'];
        $this->assertSame($invoker->compKey(), $key);

        // Only 1 unit of each comp: situational.
        $this->assertSame(CompClassifier::SITUATIONAL, $classifier->classify([], $this->units(['U_A', 'U_E', 'U_X']))['key']);

        // Units from another set entirely: falls back to the carry.
        $this->assertSame('OTHER_1', $classifier->classify([], $this->units(['OTHER_1']))['key']);
    }

    public function test_the_comps_page_lists_imported_comps_with_our_own_stats(): void
    {
        $this->artisan('tft:import-comps', ['file' => $this->file]);

        $match = TftMatch::create([
            'match_id' => 'EUW1_1', 'platform' => Platform::EUW, 'played_at' => now(),
            'game_length' => 2000, 'game_version' => 'Version 16.19', 'set_number' => 18,
        ]);
        foreach ([1, 3, 5] as $i => $placement) {
            $match->participants()->create([
                'puuid' => "p{$i}", 'placement' => $placement, 'level' => 8, 'gold_left' => 0, 'last_round' => 30,
                'traits' => [], 'units' => $this->units(['U_A', 'U_B', 'U_C', 'U_D']),
            ]);
        }

        $comps = collect(app(MetaComps::class)->forSet(18))->keyBy('label');
        $this->assertSame(3, $comps['T Invoker U A']['games'] ?? $comps->first()['games']);

        $this->get(route('comps.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('comps/Index')
                ->has('comps', 2)
                ->where('comps.0.games', 3)
                ->where('comps.0.avgPlacement', 3)
                ->where('comps.0.levelling', 'Fast 8')
                ->where('comps.1.games', 0)
                ->where('comps.1.avgPlacement', null));
    }

    public function test_unit_roles_separate_three_star_targets_carries_and_fillers(): void
    {
        $this->artisan('tft:import-comps', ['file' => $this->file]);

        $match = TftMatch::create([
            'match_id' => 'EUW1_ROLES', 'platform' => Platform::EUW, 'played_at' => now(),
            'game_length' => 2000, 'game_version' => 'Version 16.19', 'set_number' => 18,
        ]);
        foreach (range(1, 6) as $i) {
            $match->participants()->create([
                'puuid' => "p{$i}", 'placement' => 3, 'level' => 7, 'gold_left' => 0, 'last_round' => 30, 'traits' => [],
                'units' => [
                    // Always 3★ with items: the reroll target.
                    ['character_id' => 'U_A', 'tier' => 3, 'rarity' => 0, 'items' => ['i1', 'i2', 'i3']],
                    // 2★ holding items: a carry that needs 3 copies.
                    ['character_id' => 'U_B', 'tier' => 2, 'rarity' => 0, 'items' => ['i1', 'i2']],
                    // Trait bots without items.
                    ['character_id' => 'U_C', 'tier' => 2, 'rarity' => 0, 'items' => []],
                    ['character_id' => 'U_D', 'tier' => 1, 'rarity' => 0, 'items' => []],
                ],
            ]);
        }

        $comp = collect(app(MetaComps::class)->forSet(18))->firstWhere('games', 6);
        $units = collect($comp['units'])->keyBy('id');

        $this->assertSame('target', $units['U_A']['role']);
        $this->assertSame(9, $units['U_A']['needed']);
        $this->assertSame('carry', $units['U_B']['role']);
        $this->assertSame(3, $units['U_B']['needed']);
        $this->assertSame('filler', $units['U_C']['role']);
        $this->assertSame(0, $units['U_D']['needed']);
    }

    public function test_roll_level_follows_the_comps_levelling(): void
    {
        $this->assertSame(7, LobbyPredictor::rollLevel('lvl 7'));
        $this->assertSame(8, LobbyPredictor::rollLevel('Fast 8'));
        $this->assertSame(9, LobbyPredictor::rollLevel('Fast 9'));
        $this->assertSame(8, LobbyPredictor::rollLevel(null));
    }

    /**
     * @param  list<string>  $ids
     * @return list<array{character_id: string, tier: int, rarity: int, items: list<string>}>
     */
    private function units(array $ids): array
    {
        return array_map(fn (string $id) => ['character_id' => $id, 'tier' => 1, 'rarity' => 0, 'items' => []], $ids);
    }
}
