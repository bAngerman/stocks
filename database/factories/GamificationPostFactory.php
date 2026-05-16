<?php

namespace Database\Factories;

use App\Models\GamificationPost;
use App\Models\Trade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GamificationPost>
 */
class GamificationPostFactory extends Factory
{
    public function definition(): array
    {
        return [
            'trade_id' => Trade::factory(),
            'discord_message_id' => (string) $this->faker->numerify('####################'),
            'resolved_at' => null,
        ];
    }

    public function resolved(): static
    {
        return $this->state(['resolved_at' => now()]);
    }
}
