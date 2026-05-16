<?php

namespace Database\Factories;

use App\Models\UserPoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserPoint>
 */
class UserPointFactory extends Factory
{
    public function definition(): array
    {
        return [
            'discord_user_id' => (string) $this->faker->numerify('####################'),
            'discord_username' => $this->faker->userName(),
            'total_points' => $this->faker->numberBetween(0, 50),
        ];
    }
}
