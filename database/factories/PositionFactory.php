<?php

namespace Database\Factories;

use App\Models\Persona;
use App\Models\Position;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Position>
 */
class PositionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'persona_id' => Persona::factory(),
            'ticker' => $this->faker->randomElement(['AAPL', 'MSFT', 'GOOGL', 'SPY', 'QQQ']),
            'shares' => $this->faker->randomFloat(4, 1, 100),
            'average_cost' => $this->faker->randomFloat(4, 50, 500),
            'opened_at' => now()->subDays($this->faker->numberBetween(1, 30)),
        ];
    }

    public function closed(): static
    {
        return $this->state(['shares' => 0]);
    }
}
