<?php

namespace Database\Factories;

use App\Enums\Platform;
use App\Models\Player;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Player>
 */
class PlayerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'puuid' => fake()->unique()->regexify('[A-Za-z0-9_-]{78}'),
            'platform' => Platform::EUW,
            'game_name' => fake()->unique()->userName(),
            'tag_line' => fake()->regexify('[A-Z0-9]{3,5}'),
            'league' => null,
            'synced_at' => null,
            'sync_started_at' => null,
        ];
    }

    public function synced(): static
    {
        return $this->state(fn () => ['synced_at' => now()]);
    }
}
