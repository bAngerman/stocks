<?php

use App\Enums\TradeAction;
use App\Models\Persona;
use App\Models\PriceSnapshot;
use App\Models\Position;
use App\Trading\Strategies\MomentumStrategy;
use App\Trading\TradeSignal;

it('returns null when no ticker change exceeds the buy threshold', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create();
    $snapshot = PriceSnapshot::factory()->forTicker('AAPL')->create([
        'price' => 150.0,
        'change_percent' => 0.5,
    ]);

    $strategy = new MomentumStrategy();
    expect($strategy->evaluate($persona, collect([$snapshot])))->toBeNull();
});

it('returns a buy signal when change percent exceeds the buy threshold', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create([
        'strategy_parameters' => [
            'tickers' => ['AAPL'],
            'buy_threshold' => 1.5,
            'sell_threshold' => 2.0,
            'ai_confidence_min' => 0.4,
            'ai_confidence_max' => 0.7,
            'shares_per_trade' => 2,
        ],
    ]);
    $snapshot = PriceSnapshot::factory()->forTicker('AAPL')->create([
        'price' => 150.0,
        'change_percent' => 2.5,
    ]);

    $signal = (new MomentumStrategy())->evaluate($persona, collect([$snapshot]));

    expect($signal)->toBeInstanceOf(TradeSignal::class)
        ->and($signal->ticker)->toBe('AAPL')
        ->and($signal->action)->toBe(TradeAction::Buy)
        ->and($signal->shares)->toBe(2.0);
});

it('returns a sell signal when an open position drops past the sell threshold', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create([
        'strategy_parameters' => [
            'tickers' => ['AAPL'],
            'buy_threshold' => 1.5,
            'sell_threshold' => 2.0,
            'ai_confidence_min' => 0.4,
            'ai_confidence_max' => 0.7,
            'shares_per_trade' => 1,
        ],
    ]);
    Position::factory()->for($persona)->create([
        'ticker' => 'AAPL',
        'shares' => 5.0,
        'average_cost' => 160.0,
    ]);
    $snapshot = PriceSnapshot::factory()->forTicker('AAPL')->create([
        'price' => 144.0,
        'change_percent' => -3.0,
    ]);

    $signal = (new MomentumStrategy())->evaluate($persona, collect([$snapshot]));

    expect($signal)->toBeInstanceOf(TradeSignal::class)
        ->and($signal->ticker)->toBe('AAPL')
        ->and($signal->action)->toBe(TradeAction::Sell)
        ->and($signal->shares)->toBe(5.0);
});

it('sets shouldConsultAI true when confidence falls in the borderline range', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create([
        'strategy_parameters' => [
            'tickers' => ['AAPL'],
            'buy_threshold' => 1.5,
            'sell_threshold' => 2.0,
            'ai_confidence_min' => 0.4,
            'ai_confidence_max' => 0.7,
            'shares_per_trade' => 1,
        ],
    ]);
    // confidence = min(1.8 / (1.5 * 2), 1.0) = min(0.6, 1.0) = 0.6 → inside [0.4, 0.7]
    $snapshot = PriceSnapshot::factory()->forTicker('AAPL')->create([
        'price' => 150.0,
        'change_percent' => 1.8,
    ]);

    $signal = (new MomentumStrategy())->evaluate($persona, collect([$snapshot]));

    expect($signal)->not->toBeNull()
        ->and($signal->shouldConsultAI)->toBeTrue();
});

it('sets shouldConsultAI false when confidence is above the borderline range', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create([
        'strategy_parameters' => [
            'tickers' => ['AAPL'],
            'buy_threshold' => 1.5,
            'sell_threshold' => 2.0,
            'ai_confidence_min' => 0.4,
            'ai_confidence_max' => 0.7,
            'shares_per_trade' => 1,
        ],
    ]);
    // confidence = min(4.5 / 3.0, 1.0) = 1.0 → above 0.7
    $snapshot = PriceSnapshot::factory()->forTicker('AAPL')->create([
        'price' => 150.0,
        'change_percent' => 4.5,
    ]);

    $signal = (new MomentumStrategy())->evaluate($persona, collect([$snapshot]));

    expect($signal)->not->toBeNull()
        ->and($signal->shouldConsultAI)->toBeFalse();
});

it('does not return a buy signal when an open position already exists', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create();
    Position::factory()->for($persona)->create(['ticker' => 'AAPL', 'shares' => 5.0]);
    $snapshot = PriceSnapshot::factory()->forTicker('AAPL')->create([
        'change_percent' => 3.0,
    ]);

    expect((new MomentumStrategy())->evaluate($persona, collect([$snapshot])))->toBeNull();
});
