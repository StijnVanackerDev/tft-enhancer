<?php

namespace Tests\Feature;

use App\Services\Tft\LobbyPredictor;
use App\Services\Tft\RankBracket;
use Tests\TestCase;

class RankBracketTest extends TestCase
{
    public function test_a_lobby_belongs_to_the_bracket_of_its_median_ranked_player(): void
    {
        $lobby = ['EMERALD', 'EMERALD', 'DIAMOND', 'DIAMOND', 'EMERALD', null, 'MASTER', 'EMERALD'];

        $this->assertSame('emerald', RankBracket::forLobby($lobby));
        $this->assertSame('master+', RankBracket::forLobby(['CHALLENGER', 'GRANDMASTER', 'DIAMOND']));
        $this->assertNull(RankBracket::forLobby([null, null]));
    }

    public function test_brackets_map_tiers_and_have_labels(): void
    {
        // Lower ranks aren't tracked as a bracket.
        $this->assertNull(RankBracket::forTier('gold'));
        $this->assertSame(['DIAMOND'], RankBracket::tiers('diamond'));
        $this->assertSame('Master+', RankBracket::label('master+'));
        $this->assertSame('Emerald', RankBracket::label('emerald'));
    }

    public function test_units_are_searched_at_the_first_level_where_they_can_appear(): void
    {
        // 5-costs don't show up in the shop before level 7.
        $this->assertSame(7, LobbyPredictor::firstLevelWithOdds(5, 5));
        $this->assertSame(8, LobbyPredictor::firstLevelWithOdds(4, 8));
    }
}
