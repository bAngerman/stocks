<?php

namespace Database\Seeders;

use App\Enums\StrategyType;
use App\Enums\TickerSource;
use App\Enums\TickerStatus;
use App\Models\Persona;
use Illuminate\Database\Seeder;

class PersonaSeeder extends Seeder
{
    public function run(): void
    {
        $configs = [
            // --- Momentum personas ---
            [
                'name' => 'Tech Sprinter',
                'description' => 'Aggressive momentum on high-beta tech.',
                'strategy_type' => StrategyType::Momentum,
                'strategy_parameters' => [
                    'buy_threshold' => 1.5,
                    'sell_threshold' => 2.0,
                    'shares_per_trade' => 1,
                    'ai_confidence_min' => 0.4,
                    'ai_confidence_max' => 0.7,
                ],
                'tickers' => ['AAPL', 'NVDA', 'META', 'TSLA'],
            ],
            [
                'name' => 'ETF Cruiser',
                'description' => 'Low-threshold momentum on broad-market ETFs.',
                'strategy_type' => StrategyType::Momentum,
                'strategy_parameters' => [
                    'buy_threshold' => 0.6,
                    'sell_threshold' => 1.0,
                    'shares_per_trade' => 2,
                    'ai_confidence_min' => 0.5,
                    'ai_confidence_max' => 0.8,
                ],
                'tickers' => ['SPY', 'QQQ', 'IWM'],
            ],
            [
                'name' => 'High Conviction Bull',
                'description' => 'Only fires on strong moves in mega-cap tech.',
                'strategy_type' => StrategyType::Momentum,
                'strategy_parameters' => [
                    'buy_threshold' => 2.5,
                    'sell_threshold' => 3.5,
                    'shares_per_trade' => 1,
                    'ai_confidence_min' => 0.6,
                    'ai_confidence_max' => 0.9,
                ],
                'tickers' => ['AAPL', 'MSFT', 'AMZN', 'GOOGL'],
            ],
            [
                'name' => 'Sector Hawk',
                'description' => 'Sector rotation momentum via sector ETFs.',
                'strategy_type' => StrategyType::Momentum,
                'strategy_parameters' => [
                    'buy_threshold' => 1.0,
                    'sell_threshold' => 1.5,
                    'shares_per_trade' => 3,
                    'ai_confidence_min' => 0.3,
                    'ai_confidence_max' => 0.6,
                ],
                'tickers' => ['XLK', 'XLF', 'XLE', 'XLV'],
            ],

            // --- Mean Reversion personas ---
            [
                'name' => 'Snap Back',
                'description' => 'Aggressive mean reversion on volatile tech. Needs 5 data points.',
                'strategy_type' => StrategyType::MeanReversion,
                'strategy_parameters' => [
                    'lookback_periods' => 10,
                    'min_data_points' => 5,
                    'deviation_threshold' => 2.0,
                    'shares_per_trade' => 1,
                    'ai_confidence_min' => 0.4,
                    'ai_confidence_max' => 0.7,
                ],
                'tickers' => ['TSLA', 'AMD', 'NVDA'],
            ],
            [
                'name' => 'Blue Chip Bouncer',
                'description' => 'Patient mean reversion on stable blue chips. Needs 10 data points.',
                'strategy_type' => StrategyType::MeanReversion,
                'strategy_parameters' => [
                    'lookback_periods' => 20,
                    'min_data_points' => 10,
                    'deviation_threshold' => 3.0,
                    'shares_per_trade' => 1,
                    'ai_confidence_min' => 0.5,
                    'ai_confidence_max' => 0.8,
                ],
                'tickers' => ['MSFT', 'AAPL', 'JNJ', 'PG'],
            ],
            [
                'name' => 'Index Oscillator',
                'description' => 'Tight oscillation trading on index ETFs. Needs 8 data points.',
                'strategy_type' => StrategyType::MeanReversion,
                'strategy_parameters' => [
                    'lookback_periods' => 15,
                    'min_data_points' => 8,
                    'deviation_threshold' => 1.5,
                    'shares_per_trade' => 2,
                    'ai_confidence_min' => 0.3,
                    'ai_confidence_max' => 0.6,
                ],
                'tickers' => ['SPY', 'QQQ'],
            ],
            [
                'name' => 'Patient Contrarian',
                'description' => 'Waits for extreme deviations; always consults AI. Needs 15 data points.',
                'strategy_type' => StrategyType::MeanReversion,
                'strategy_parameters' => [
                    'lookback_periods' => 30,
                    'min_data_points' => 15,
                    'deviation_threshold' => 4.0,
                    'shares_per_trade' => 1,
                    'ai_confidence_min' => 0.0,
                    'ai_confidence_max' => 1.0,
                ],
                'tickers' => ['TSLA', 'AMZN', 'META', 'NVDA'],
            ],
        ];

        foreach ($configs as $config) {
            $tickers = $config['tickers'];
            unset($config['tickers']);

            $persona = Persona::updateOrCreate(
                ['name' => $config['name']],
                array_merge($config, ['cash_balance' => 10000.00, 'is_active' => true])
            );

            foreach ($tickers as $ticker) {
                $persona->tickers()->firstOrCreate(
                    ['ticker' => $ticker],
                    ['status' => TickerStatus::Active, 'source' => TickerSource::Initial]
                );
            }
        }
    }
}
