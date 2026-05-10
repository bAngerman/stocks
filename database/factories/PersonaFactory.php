<?php

namespace Database\Factories;

use App\Enums\StrategyType;
use App\Models\Persona;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Persona>
 */
class PersonaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true),
            'description' => $this->faker->sentence(),
            'cash_balance' => 10000.00,
            'strategy_type' => StrategyType::Momentum,
            'strategy_parameters' => [
                'tickers' => ['AAPL', 'MSFT'],
                'buy_threshold' => 1.5,
                'sell_threshold' => 2.0,
                'ai_confidence_min' => 0.4,
                'ai_confidence_max' => 0.7,
                'shares_per_trade' => 1,
            ],
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function withTickers(array $tickers): static
    {
        return $this->state(function (array $attributes) use ($tickers) {
            $params = $attributes['strategy_parameters'];
            $params['tickers'] = $tickers;

            return ['strategy_parameters' => $params];
        });
    }
}
