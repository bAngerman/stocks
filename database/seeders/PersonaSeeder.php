<?php

namespace Database\Seeders;

use App\Enums\StrategyType;
use App\Models\Persona;
use Illuminate\Database\Seeder;

class PersonaSeeder extends Seeder
{
    public function run(): void
    {
        Persona::firstOrCreate(
            ['name' => 'Momentum Bot'],
            [
                'description' => 'Buys on strong upward momentum, sells on sharp declines.',
                'cash_balance' => 10000.00,
                'strategy_type' => StrategyType::Momentum,
                'strategy_parameters' => [
                    'tickers' => ['AAPL', 'MSFT', 'SPY'],
                    'buy_threshold' => 1.5,
                    'sell_threshold' => 2.0,
                    'ai_confidence_min' => 0.4,
                    'ai_confidence_max' => 0.7,
                    'shares_per_trade' => 1,
                ],
                'is_active' => true,
            ],
        );
    }
}
