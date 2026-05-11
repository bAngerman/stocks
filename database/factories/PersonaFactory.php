<?php

namespace Database\Factories;

use App\Enums\StrategyType;
use App\Enums\TickerSource;
use App\Enums\TickerStatus;
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
        return $this->afterCreating(function (Persona $persona) use ($tickers) {
            foreach ($tickers as $ticker) {
                $persona->tickers()->create([
                    'ticker' => $ticker,
                    'status' => TickerStatus::Active,
                    'source' => TickerSource::Initial,
                ]);
            }
        });
    }
}
