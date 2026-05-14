<?php

namespace Database\Factories;

use App\Enums\TradeAction;
use App\Models\Persona;
use App\Models\Trade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Trade>
 */
class TradeFactory extends Factory
{
    public function definition(): array
    {
        $shares = $this->faker->randomFloat(4, 1, 10);
        $price = $this->faker->randomFloat(4, 50, 500);

        return [
            'persona_id' => Persona::factory(),
            'ticker' => $this->faker->randomElement(['AAPL', 'MSFT', 'GOOGL', 'SPY']),
            'action' => $this->faker->randomElement(TradeAction::cases()),
            'shares' => $shares,
            'price_per_share' => $price,
            'cost_basis' => null,
            'total_value' => round($shares * $price, 2),
            'signal_reason' => $this->faker->sentence(),
            'ai_rationale' => null,
            'executed_at' => now(),
        ];
    }

    public function buy(): static
    {
        return $this->state(['action' => TradeAction::Buy]);
    }

    public function sell(): static
    {
        return $this->state(['action' => TradeAction::Sell]);
    }

    public function aiAssisted(): static
    {
        return $this->state(['ai_rationale' => fake()->sentence()]);
    }
}
