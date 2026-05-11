<?php

use App\Enums\StrategyType;
use App\Enums\TradeAction;
use App\Models\Persona;
use App\Models\Position;
use App\Models\PriceSnapshot;
use App\Trading\Strategies\MeanReversionStrategy;
use App\Trading\TradeSignal;
use Carbon\Carbon;

it('returns null when snapshot collection is empty', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create([
        'strategy_type' => StrategyType::MeanReversion,
        'strategy_parameters' => [
            'lookback_periods' => 10,
            'min_data_points' => 5,
            'deviation_threshold' => 3.0,
            'shares_per_trade' => 1,
            'ai_confidence_min' => 0.4,
            'ai_confidence_max' => 0.7,
        ],
    ]);

    expect((new MeanReversionStrategy)->evaluate($persona, collect()))->toBeNull();
});

it('returns null when fewer than min_data_points historical snapshots exist', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create([
        'strategy_type' => StrategyType::MeanReversion,
        'strategy_parameters' => [
            'lookback_periods' => 10,
            'min_data_points' => 5,
            'deviation_threshold' => 3.0,
            'shares_per_trade' => 1,
            'ai_confidence_min' => 0.4,
            'ai_confidence_max' => 0.7,
        ],
    ]);

    $base = now();

    foreach (range(1, 3) as $i) {
        PriceSnapshot::factory()->forTicker('AAPL')->create([
            'price' => 100.00,
            'fetched_at' => $base->copy()->subMinutes($i * 15),
        ]);
    }

    $current = PriceSnapshot::factory()->forTicker('AAPL')->create([
        'price' => 90.00,
        'fetched_at' => $base,
    ]);

    expect((new MeanReversionStrategy)->evaluate($persona, collect([$current])))->toBeNull();
});

it('returns a buy signal when price is sufficiently below the historical mean', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create([
        'strategy_type' => StrategyType::MeanReversion,
        'strategy_parameters' => [
            'lookback_periods' => 10,
            'min_data_points' => 5,
            'deviation_threshold' => 3.0,
            'shares_per_trade' => 2,
            'ai_confidence_min' => 0.4,
            'ai_confidence_max' => 0.7,
        ],
    ]);

    $base = now();

    foreach (range(1, 10) as $i) {
        PriceSnapshot::factory()->forTicker('AAPL')->create([
            'price' => 100.00,
            'fetched_at' => $base->copy()->subMinutes($i * 15),
        ]);
    }

    $current = PriceSnapshot::factory()->forTicker('AAPL')->create([
        'price' => 94.00,
        'fetched_at' => $base,
    ]);

    $signal = (new MeanReversionStrategy)->evaluate($persona, collect([$current]));

    expect($signal)->toBeInstanceOf(TradeSignal::class)
        ->and($signal->ticker)->toBe('AAPL')
        ->and($signal->action)->toBe(TradeAction::Buy)
        ->and($signal->shares)->toBe(2.0);
});

it('computes confidence as clamped deviation ratio', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create([
        'strategy_type' => StrategyType::MeanReversion,
        'strategy_parameters' => [
            'lookback_periods' => 5,
            'min_data_points' => 3,
            'deviation_threshold' => 5.0,
            'shares_per_trade' => 2,
            'ai_confidence_min' => 0.0,
            'ai_confidence_max' => 0.5,
        ],
    ]);

    // mean = 100, current = 90 → deviation = -10% → confidence = 10 / (5 * 2) = 1.0 (clamped)
    $baseTime = Carbon::parse('2026-05-12 14:00:00');
    foreach (range(1, 3) as $i) {
        PriceSnapshot::factory()->forTicker('AAPL')->create([
            'price' => '100.00',
            'fetched_at' => $baseTime->copy()->subMinutes($i * 10),
        ]);
    }

    $currentSnapshot = PriceSnapshot::factory()->forTicker('AAPL')->create([
        'price' => '90.00',
        'fetched_at' => $baseTime,
    ]);

    $strategy = new MeanReversionStrategy;
    $signal = $strategy->evaluate($persona, collect([$currentSnapshot]));

    expect($signal)->not->toBeNull()
        ->and($signal->confidence)->toBe(1.0);
});

it('returns null when price is within the deviation threshold', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create([
        'strategy_type' => StrategyType::MeanReversion,
        'strategy_parameters' => [
            'lookback_periods' => 10,
            'min_data_points' => 5,
            'deviation_threshold' => 3.0,
            'shares_per_trade' => 1,
            'ai_confidence_min' => 0.4,
            'ai_confidence_max' => 0.7,
        ],
    ]);

    $base = now();

    foreach (range(1, 10) as $i) {
        PriceSnapshot::factory()->forTicker('AAPL')->create([
            'price' => 100.00,
            'fetched_at' => $base->copy()->subMinutes($i * 15),
        ]);
    }

    $current = PriceSnapshot::factory()->forTicker('AAPL')->create([
        'price' => 98.50,
        'fetched_at' => $base,
    ]);

    expect((new MeanReversionStrategy)->evaluate($persona, collect([$current])))->toBeNull();
});

it('returns a sell signal when price is sufficiently above the mean and a position is held', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create([
        'strategy_type' => StrategyType::MeanReversion,
        'strategy_parameters' => [
            'lookback_periods' => 10,
            'min_data_points' => 5,
            'deviation_threshold' => 3.0,
            'shares_per_trade' => 1,
            'ai_confidence_min' => 0.4,
            'ai_confidence_max' => 0.7,
        ],
    ]);

    Position::factory()->for($persona)->create([
        'ticker' => 'AAPL',
        'shares' => 3.0,
        'average_cost' => 100.00,
    ]);

    $base = now();

    foreach (range(1, 10) as $i) {
        PriceSnapshot::factory()->forTicker('AAPL')->create([
            'price' => 100.00,
            'fetched_at' => $base->copy()->subMinutes($i * 15),
        ]);
    }

    $current = PriceSnapshot::factory()->forTicker('AAPL')->create([
        'price' => 107.00,
        'fetched_at' => $base,
    ]);

    $signal = (new MeanReversionStrategy)->evaluate($persona, collect([$current]));

    expect($signal)->toBeInstanceOf(TradeSignal::class)
        ->and($signal->action)->toBe(TradeAction::Sell)
        ->and($signal->shares)->toBe(3.0);
});

it('does not return a buy signal when a position is already held', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create([
        'strategy_type' => StrategyType::MeanReversion,
        'strategy_parameters' => [
            'lookback_periods' => 10,
            'min_data_points' => 5,
            'deviation_threshold' => 3.0,
            'shares_per_trade' => 1,
            'ai_confidence_min' => 0.4,
            'ai_confidence_max' => 0.7,
        ],
    ]);

    Position::factory()->for($persona)->create(['ticker' => 'AAPL', 'shares' => 2.0]);

    $base = now();

    foreach (range(1, 10) as $i) {
        PriceSnapshot::factory()->forTicker('AAPL')->create([
            'price' => 100.00,
            'fetched_at' => $base->copy()->subMinutes($i * 15),
        ]);
    }

    $current = PriceSnapshot::factory()->forTicker('AAPL')->create([
        'price' => 90.00,
        'fetched_at' => $base,
    ]);

    expect((new MeanReversionStrategy)->evaluate($persona, collect([$current])))->toBeNull();
});

it('returns the highest-confidence signal when multiple tickers qualify', function () {
    $persona = Persona::factory()->withTickers(['AAPL', 'MSFT'])->create([
        'strategy_type' => StrategyType::MeanReversion,
        'strategy_parameters' => [
            'lookback_periods' => 10,
            'min_data_points' => 5,
            'deviation_threshold' => 3.0,
            'shares_per_trade' => 1,
            'ai_confidence_min' => 0.4,
            'ai_confidence_max' => 0.7,
        ],
    ]);

    $base = now();

    foreach (['AAPL', 'MSFT'] as $ticker) {
        foreach (range(1, 10) as $i) {
            PriceSnapshot::factory()->forTicker($ticker)->create([
                'price' => 100.00,
                'fetched_at' => $base->copy()->subMinutes($i * 15),
            ]);
        }
    }

    // AAPL: -6% deviation (higher confidence)
    $aaplSnapshot = PriceSnapshot::factory()->forTicker('AAPL')->create([
        'price' => 94.00,
        'fetched_at' => $base,
    ]);
    // MSFT: -4% deviation (lower confidence)
    $msftSnapshot = PriceSnapshot::factory()->forTicker('MSFT')->create([
        'price' => 96.00,
        'fetched_at' => $base,
    ]);

    $signal = (new MeanReversionStrategy)->evaluate($persona, collect([$aaplSnapshot, $msftSnapshot]));

    expect($signal)->not->toBeNull()
        ->and($signal->ticker)->toBe('AAPL');
});

it('sets shouldConsultAI false when confidence is above the borderline range', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create([
        'strategy_type' => StrategyType::MeanReversion,
        'strategy_parameters' => [
            'lookback_periods' => 10,
            'min_data_points' => 5,
            'deviation_threshold' => 3.0,
            'shares_per_trade' => 1,
            'ai_confidence_min' => 0.4,
            'ai_confidence_max' => 0.7,
        ],
    ]);

    $base = now();

    foreach (range(1, 10) as $i) {
        PriceSnapshot::factory()->forTicker('AAPL')->create([
            'price' => 100.00,
            'fetched_at' => $base->copy()->subMinutes($i * 15),
        ]);
    }

    // deviation = -4.5% → confidence = min(4.5 / 6.0, 1.0) = 0.75 → outside [0.4, 0.7]
    $current = PriceSnapshot::factory()->forTicker('AAPL')->create([
        'price' => 95.50,
        'fetched_at' => $base,
    ]);

    $signal = (new MeanReversionStrategy)->evaluate($persona, collect([$current]));

    expect($signal)->not->toBeNull()
        ->and($signal->shouldConsultAI)->toBeFalse();
});

it('sets shouldConsultAI true when confidence falls in the borderline range', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create([
        'strategy_type' => StrategyType::MeanReversion,
        'strategy_parameters' => [
            'lookback_periods' => 10,
            'min_data_points' => 5,
            'deviation_threshold' => 3.0,
            'shares_per_trade' => 1,
            'ai_confidence_min' => 0.4,
            'ai_confidence_max' => 0.7,
        ],
    ]);

    $base = now();

    foreach (range(1, 10) as $i) {
        PriceSnapshot::factory()->forTicker('AAPL')->create([
            'price' => 100.00,
            'fetched_at' => $base->copy()->subMinutes($i * 15),
        ]);
    }

    // deviation = -4% → confidence = min(4.0 / 6.0, 1.0) = 0.67 → inside [0.4, 0.7]
    $current = PriceSnapshot::factory()->forTicker('AAPL')->create([
        'price' => 96.00,
        'fetched_at' => $base,
    ]);

    $signal = (new MeanReversionStrategy)->evaluate($persona, collect([$current]));

    expect($signal)->not->toBeNull()
        ->and($signal->shouldConsultAI)->toBeTrue();
});
