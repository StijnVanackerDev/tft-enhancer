<?php

namespace Database\Seeders;

use App\Enums\Platform;
use App\Models\Player;
use App\Models\TftMatch;
use Illuminate\Database\Seeder;

/**
 * Fake player with random Set 17 games, for working on the UI without a
 * Riot API key: php artisan db:seed --class=DemoSeeder
 * Then open /players/euw1/Demo Player-DEMO
 */
class DemoSeeder extends Seeder
{
    private const UNITS = [
        'TFT17_Jinx', 'TFT17_Poppy', 'TFT17_Veigar', 'TFT17_Caitlyn', 'TFT17_Akali', 'TFT17_Gnar',
        'TFT17_Illaoi', 'TFT17_Aurora', 'TFT17_Kaisa', 'TFT17_Viktor', 'TFT17_Karma', 'TFT17_Corki',
        'TFT17_Nami', 'TFT17_Riven', 'TFT17_Jhin', 'TFT17_Vex', 'TFT17_Fiora', 'TFT17_Sona',
    ];

    private const TRAITS = ['TFT17_Anima', 'TFT17_Meeple', 'TFT17_DarkStar', 'TFT17_Psionic', 'TFT17_Stargazer', 'TFT17_Sniper'];

    private const ITEMS = ['TFT_Item_InfinityEdge', 'TFT_Item_GuinsoosRageblade', 'TFT_Item_JeweledGauntlet', 'TFT_Item_WarmogsArmor', 'TFT_Item_Bloodthirster'];

    public function run(): void
    {
        $player = Player::updateOrCreate(['puuid' => 'demo-player'], [
            'platform' => Platform::EUW,
            'game_name' => 'Demo Player',
            'tag_line' => 'DEMO',
            'synced_at' => now(),
            'league' => [[
                'queueType' => 'RANKED_TFT', 'tier' => 'PLATINUM', 'rank' => 'II',
                'leaguePoints' => 63, 'wins' => 21, 'losses' => 64,
            ]],
        ]);

        for ($i = 0; $i < 20; $i++) {
            $match = TftMatch::updateOrCreate(['match_id' => "DEMO_{$i}"], [
                'platform' => Platform::EUW,
                'played_at' => now()->subHours($i * 3 + 1),
                'game_length' => random_int(1500, 2300),
                'game_version' => 'Version 16.19.100.1',
                'queue_id' => 1100,
                'set_number' => 17,
                'game_type' => 'standard',
            ]);

            $match->participants()->updateOrCreate(['puuid' => $player->puuid], [
                'placement' => random_int(1, 8),
                'level' => random_int(7, 9),
                'gold_left' => random_int(0, 30),
                'last_round' => random_int(24, 38),
                'damage_to_players' => random_int(20, 160),
                'players_eliminated' => random_int(0, 2),
                'traits' => array_map(fn (string $t) => [
                    'name' => $t,
                    'num_units' => random_int(2, 6),
                    'style' => random_int(0, 4),
                    'tier_current' => 1,
                    'tier_total' => 3,
                ], self::TRAITS),
                'units' => array_map(fn (string $u) => [
                    'character_id' => $u,
                    'tier' => random_int(1, 10) > 8 ? 3 : random_int(1, 2),
                    'rarity' => 0,
                    'items' => array_slice(self::ITEMS, 0, random_int(0, 3)),
                ], array_rand(array_flip(self::UNITS), 8)),
            ]);
        }
    }
}
