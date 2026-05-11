<?php

use App\Enums\TickerSource;
use App\Enums\TickerStatus;
use App\Enums\TradeAction;
use App\Jobs\AIEvaluationJob;
use App\Jobs\EvaluatePersonaJob;
use App\Jobs\ExecuteTradeJob;
use App\Models\Persona;
use App\Models\PriceSnapshot;
use App\Services\MarketDataService;
use App\Trading\MarketQuote;
use Carbon\Carbon;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Carbon::setTestNow('2026-05-12 14:00:00'); // Tuesday 10am ET (UTC-4 → 14:00 UTC)
    Bus::fake()->except([EvaluatePersonaJob::class]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('dispatches ExecuteTradeJob directly for a high-confidence signal', function () {
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

    // confidence = min(4.5 / 3.0, 1.0) = 1.0 → not in [0.4, 0.7], skips AI
    $mockService = Mockery::mock(MarketDataService::class);
    $mockService->shouldReceive('getQuote')
        ->with('AAPL')
        ->andReturn(new MarketQuote('AAPL', 150.0, 4.5, now()));
    $this->app->instance(MarketDataService::class, $mockService);

    EvaluatePersonaJob::dispatchSync($persona);

    Bus::assertDispatched(ExecuteTradeJob::class, fn ($job) => $job->signal->ticker === 'AAPL' &&
        $job->signal->action === TradeAction::Buy
    );
    Bus::assertNotDispatched(AIEvaluationJob::class);
});

it('dispatches AIEvaluationJob for a borderline-confidence signal', function () {
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

    // confidence = min(1.8 / 3.0, 1.0) = 0.6 → inside [0.4, 0.7], goes to AI
    $mockService = Mockery::mock(MarketDataService::class);
    $mockService->shouldReceive('getQuote')
        ->with('AAPL')
        ->andReturn(new MarketQuote('AAPL', 150.0, 1.8, now()));
    $this->app->instance(MarketDataService::class, $mockService);

    EvaluatePersonaJob::dispatchSync($persona);

    Bus::assertDispatched(AIEvaluationJob::class);
    Bus::assertNotDispatched(ExecuteTradeJob::class);
});

it('dispatches nothing when strategy returns no signal', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create();

    // change_percent 0.5 is below buy_threshold 1.5
    $mockService = Mockery::mock(MarketDataService::class);
    $mockService->shouldReceive('getQuote')
        ->with('AAPL')
        ->andReturn(new MarketQuote('AAPL', 150.0, 0.5, now()));
    $this->app->instance(MarketDataService::class, $mockService);

    EvaluatePersonaJob::dispatchSync($persona);

    Bus::assertNothingDispatched();
});

it('reuses an existing PriceSnapshot within the polling window', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create();
    PriceSnapshot::factory()->forTicker('AAPL')->create([
        'price' => 150.0,
        'change_percent' => 0.5,
        'fetched_at' => now()->subMinutes(5),
    ]);

    $mockService = Mockery::mock(MarketDataService::class);
    $mockService->shouldNotReceive('getQuote');
    $this->app->instance(MarketDataService::class, $mockService);

    EvaluatePersonaJob::dispatchSync($persona);
});

it('skips evaluation before market open', function () {
    Carbon::setTestNow('2026-05-12 13:00:00'); // 9:00am ET — before 9:30am open

    $persona = Persona::factory()->withTickers(['AAPL'])->create();
    $mockService = Mockery::mock(MarketDataService::class);
    $mockService->shouldNotReceive('getQuote');
    $this->app->instance(MarketDataService::class, $mockService);

    EvaluatePersonaJob::dispatchSync($persona);

    Bus::assertNothingDispatched();
});

it('skips evaluation after market close', function () {
    Carbon::setTestNow('2026-05-12 20:30:00'); // 4:30pm ET — after 4:00pm close

    $persona = Persona::factory()->withTickers(['AAPL'])->create();
    $mockService = Mockery::mock(MarketDataService::class);
    $mockService->shouldNotReceive('getQuote');
    $this->app->instance(MarketDataService::class, $mockService);

    EvaluatePersonaJob::dispatchSync($persona);

    Bus::assertNothingDispatched();
});

it('skips evaluation on weekends', function () {
    Carbon::setTestNow('2026-05-10 14:00:00'); // Sunday 10am ET

    $persona = Persona::factory()->withTickers(['AAPL'])->create();
    $mockService = Mockery::mock(MarketDataService::class);
    $mockService->shouldNotReceive('getQuote');
    $this->app->instance(MarketDataService::class, $mockService);

    EvaluatePersonaJob::dispatchSync($persona);

    Bus::assertNothingDispatched();
});

it('promotes a candidate ticker to active when it generates a signal', function () {
    $persona = Persona::factory()->create([
        'strategy_parameters' => [
            'buy_threshold' => 1.0,
            'sell_threshold' => 2.0,
            'ai_confidence_min' => 0.5,
            'ai_confidence_max' => 0.8,
            'shares_per_trade' => 1,
        ],
    ]);
    $persona->tickers()->create([
        'ticker' => 'NVDA',
        'status' => TickerStatus::Candidate,
        'source' => TickerSource::AiDiscovered,
        'ai_rationale' => 'AI pick',
    ]);

    // confidence = min(2.5 / 2.0, 1.0) = 1.0 — outside [0.5, 0.8] → ExecuteTradeJob dispatched
    $mockService = Mockery::mock(MarketDataService::class);
    $mockService->shouldReceive('getQuote')
        ->with('NVDA')
        ->andReturn(new MarketQuote('NVDA', 500.00, 2.5, now()));
    $this->app->instance(MarketDataService::class, $mockService);

    EvaluatePersonaJob::dispatchSync($persona);

    $persona->refresh();
    expect($persona->candidateTickers)->toHaveCount(0)
        ->and($persona->activeTickers->where('ticker', 'NVDA'))->toHaveCount(1)
        ->and($persona->tickers->firstWhere('ticker', 'NVDA')->promoted_at)->not->toBeNull();
});

it('increments evaluations_without_signal on candidates that produced no signal', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create([
        'strategy_parameters' => [
            'buy_threshold' => 100.0,
            'sell_threshold' => 100.0,
            'ai_confidence_min' => 0.4,
            'ai_confidence_max' => 0.7,
            'shares_per_trade' => 1,
        ],
    ]);
    $persona->tickers()->create([
        'ticker' => 'TSLA',
        'status' => TickerStatus::Candidate,
        'source' => TickerSource::AiDiscovered,
        'ai_rationale' => 'test',
        'evaluations_without_signal' => 5,
    ]);

    $mockService = Mockery::mock(MarketDataService::class);
    $mockService->shouldReceive('getQuote')->with('AAPL')
        ->andReturn(new MarketQuote('AAPL', 150.00, 0.5, now()));
    $mockService->shouldReceive('getQuote')->with('TSLA')
        ->andReturn(new MarketQuote('TSLA', 200.00, 0.5, now()));
    $this->app->instance(MarketDataService::class, $mockService);

    EvaluatePersonaJob::dispatchSync($persona);

    expect($persona->fresh()->tickers->firstWhere('ticker', 'TSLA')->evaluations_without_signal)->toBe(6);
});

it('deletes candidate tickers that reach 20 evaluations without a signal', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create([
        'strategy_parameters' => [
            'buy_threshold' => 100.0,
            'sell_threshold' => 100.0,
            'ai_confidence_min' => 0.4,
            'ai_confidence_max' => 0.7,
            'shares_per_trade' => 1,
        ],
    ]);
    $persona->tickers()->create([
        'ticker' => 'TSLA',
        'status' => TickerStatus::Candidate,
        'source' => TickerSource::AiDiscovered,
        'ai_rationale' => 'test',
        'evaluations_without_signal' => 19,
    ]);

    $mockService = Mockery::mock(MarketDataService::class);
    $mockService->shouldReceive('getQuote')->with('AAPL')
        ->andReturn(new MarketQuote('AAPL', 150.00, 0.5, now()));
    $mockService->shouldReceive('getQuote')->with('TSLA')
        ->andReturn(new MarketQuote('TSLA', 200.00, 0.5, now()));
    $this->app->instance(MarketDataService::class, $mockService);

    EvaluatePersonaJob::dispatchSync($persona);

    expect($persona->fresh()->tickers->where('ticker', 'TSLA'))->toHaveCount(0);
});
