<?php

use App\Enums\TradeAction;
use App\Jobs\ExecuteTradeJob;
use App\Jobs\PostTradeLotteryJob;
use App\Models\Persona;
use App\Models\Position;
use App\Models\Trade;
use App\Trading\TradeSignal;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // Disable the lottery by default so non-lottery tests don't trigger
    // PostTradeLotteryJob (which would hit the real Discord API under sync queue).
    config(['trading.trade_lottery_probability' => 0]);
});

it('creates a trade record on a buy', function () {
    $persona = Persona::factory()->create(['cash_balance' => 10000.00]);
    $signal = new TradeSignal(
        ticker: 'AAPL',
        action: TradeAction::Buy,
        shares: 1.0,
        reason: 'Price up 2.5%',
        confidence: 0.9,
        shouldConsultAI: false,
    );

    ExecuteTradeJob::dispatchSync($persona, $signal, 150.00);

    $trade = Trade::first();
    expect($trade)->not->toBeNull()
        ->and($trade->ticker)->toBe('AAPL')
        ->and($trade->action)->toBe(TradeAction::Buy)
        ->and($trade->shares)->toBe('1.0000')
        ->and($trade->price_per_share)->toBe('150.0000')
        ->and($trade->total_value)->toBe('150.00')
        ->and($trade->signal_reason)->toBe('Price up 2.5%')
        ->and($trade->ai_rationale)->toBeNull();
});

it('deducts cash on a buy', function () {
    $persona = Persona::factory()->create(['cash_balance' => 10000.00]);
    $signal = new TradeSignal('AAPL', TradeAction::Buy, 2.0, 'Signal', 0.9, false);

    ExecuteTradeJob::dispatchSync($persona, $signal, 200.00);

    expect($persona->fresh()->cash_balance)->toBe('9600.00');
});

it('creates a new position on the first buy', function () {
    $persona = Persona::factory()->create(['cash_balance' => 10000.00]);
    $signal = new TradeSignal('AAPL', TradeAction::Buy, 3.0, 'Signal', 0.9, false);

    ExecuteTradeJob::dispatchSync($persona, $signal, 100.00);

    $position = Position::where('persona_id', $persona->id)->where('ticker', 'AAPL')->first();
    expect($position->shares)->toBe('3.0000')
        ->and($position->average_cost)->toBe('100.0000');
});

it('updates average cost on a subsequent buy of the same ticker', function () {
    $persona = Persona::factory()->create(['cash_balance' => 10000.00]);
    Position::factory()->for($persona)->create([
        'ticker' => 'AAPL',
        'shares' => 2.0,
        'average_cost' => 100.00,
    ]);
    $signal = new TradeSignal('AAPL', TradeAction::Buy, 2.0, 'Signal', 0.9, false);

    // 2 shares @ $100 + 2 shares @ $120 → avg = $110
    ExecuteTradeJob::dispatchSync($persona, $signal, 120.00);

    $position = Position::where('persona_id', $persona->id)->where('ticker', 'AAPL')->first();
    expect($position->shares)->toBe('4.0000')
        ->and($position->average_cost)->toBe('110.0000');
});

it('credits cash and reduces shares on a sell', function () {
    $persona = Persona::factory()->create(['cash_balance' => 5000.00]);
    Position::factory()->for($persona)->create([
        'ticker' => 'AAPL',
        'shares' => 5.0,
        'average_cost' => 100.00,
    ]);
    $signal = new TradeSignal('AAPL', TradeAction::Sell, 5.0, 'Signal', 0.9, false);

    ExecuteTradeJob::dispatchSync($persona, $signal, 150.00);

    $position = Position::where('persona_id', $persona->id)->where('ticker', 'AAPL')->first();
    expect((float) $position->shares)->toBe(0.0)
        ->and($persona->fresh()->cash_balance)->toBe('5750.00');
});

it('stores ai_rationale when provided', function () {
    $persona = Persona::factory()->create(['cash_balance' => 10000.00]);
    $signal = new TradeSignal('AAPL', TradeAction::Buy, 1.0, 'Algo signal', 0.6, true);

    ExecuteTradeJob::dispatchSync($persona, $signal, 150.00, 'AI approved: strong momentum');

    expect(Trade::first()->ai_rationale)->toBe('AI approved: strong momentum');
});

it('skips a buy when the persona has insufficient cash', function () {
    $persona = Persona::factory()->create(['cash_balance' => 10.00]);
    $signal = new TradeSignal('AAPL', TradeAction::Buy, 1.0, 'Signal', 0.9, false);

    ExecuteTradeJob::dispatchSync($persona, $signal, 150.00);

    expect(Trade::count())->toBe(0);
});

it('sells only available shares when signal requests more than held', function () {
    $persona = Persona::factory()->create(['cash_balance' => 5000.00]);
    Position::factory()->for($persona)->create([
        'ticker' => 'AAPL',
        'shares' => 3.0,
        'average_cost' => 100.00,
    ]);
    $signal = new TradeSignal('AAPL', TradeAction::Sell, 10.0, 'Signal', 0.9, false);

    ExecuteTradeJob::dispatchSync($persona, $signal, 150.00);

    $trade = Trade::first();
    expect((float) $trade->shares)->toBe(3.0)
        ->and($persona->fresh()->cash_balance)->toBe('5450.00');
});

it('dispatches PostTradeLotteryJob after a successful trade when lottery wins', function () {
    Queue::fake([PostTradeLotteryJob::class]);
    config(['trading.trade_lottery_probability' => 100]);

    $persona = Persona::factory()->create(['cash_balance' => 10000.00]);
    $signal = new TradeSignal('AAPL', TradeAction::Buy, 1.0, 'Signal', 0.9, false);

    ExecuteTradeJob::dispatchSync($persona, $signal, 100.00);

    Queue::assertPushed(PostTradeLotteryJob::class);
});

it('does not dispatch PostTradeLotteryJob when lottery probability is zero', function () {
    Queue::fake([PostTradeLotteryJob::class]);
    config(['trading.trade_lottery_probability' => 0]);

    $persona = Persona::factory()->create(['cash_balance' => 10000.00]);
    $signal = new TradeSignal('AAPL', TradeAction::Buy, 1.0, 'Signal', 0.9, false);

    ExecuteTradeJob::dispatchSync($persona, $signal, 100.00);

    Queue::assertNotPushed(PostTradeLotteryJob::class);
});

it('does not dispatch PostTradeLotteryJob when a buy is skipped due to insufficient cash', function () {
    Queue::fake([PostTradeLotteryJob::class]);
    config(['trading.trade_lottery_probability' => 100]);

    $persona = Persona::factory()->create(['cash_balance' => 1.00]);
    $signal = new TradeSignal('AAPL', TradeAction::Buy, 10.0, 'Signal', 0.9, false);

    ExecuteTradeJob::dispatchSync($persona, $signal, 500.00);

    Queue::assertNotPushed(PostTradeLotteryJob::class);
});
