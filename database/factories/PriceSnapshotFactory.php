<?php

namespace Database\Factories;

use App\Models\PriceSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceSnapshot>
 */
class PriceSnapshotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ticker' => $this->faker->randomElement(['AAPL', 'MSFT', 'GOOGL', 'SPY', 'QQQ']),
            'price' => $this->faker->randomFloat(4, 50, 500),
            'change_percent' => $this->faker->randomFloat(4, -5.0, 5.0),
            'fetched_at' => now(),
        ];
    }

    public function stale(): static
    {
        return $this->state(['fetched_at' => now()->subHour()]);
    }

    public function forTicker(string $ticker): static
    {
        return $this->state(['ticker' => $ticker]);
    }
}
