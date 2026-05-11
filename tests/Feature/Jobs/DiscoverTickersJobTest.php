<?php

use App\Enums\TickerSource;
use App\Enums\TickerStatus;
use App\Jobs\DiscoverTickersJob;
use App\Models\Persona;
use App\Services\TickerDiscoveryService;

it('creates candidate tickers from discovery suggestions', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create();

    $service = $this->mock(TickerDiscoveryService::class);
    $service->shouldReceive('suggest')
        ->once()
        ->with(Mockery::on(fn ($p) => $p->is($persona)))
        ->andReturn([
            ['ticker' => 'NVDA', 'rationale' => 'Strong AI chip demand'],
            ['ticker' => 'META', 'rationale' => 'Ad revenue recovery'],
        ]);

    (new DiscoverTickersJob($persona))->handle($service);

    $persona->refresh();
    expect($persona->candidateTickers)->toHaveCount(2)
        ->and($persona->candidateTickers->pluck('ticker')->toArray())->toContain('NVDA', 'META')
        ->and($persona->candidateTickers->firstWhere('ticker', 'NVDA')->source)->toBe(TickerSource::AiDiscovered)
        ->and($persona->candidateTickers->firstWhere('ticker', 'NVDA')->ai_rationale)->toBe('Strong AI chip demand');
});

it('skips tickers that are already in the persona watchlist', function () {
    $persona = Persona::factory()->withTickers(['AAPL', 'NVDA'])->create();

    $service = $this->mock(TickerDiscoveryService::class);
    $service->shouldReceive('suggest')->andReturn([
        ['ticker' => 'NVDA', 'rationale' => 'Already active — should be skipped'],
        ['ticker' => 'TSLA', 'rationale' => 'New suggestion'],
    ]);

    (new DiscoverTickersJob($persona))->handle($service);

    $persona->refresh();
    expect($persona->tickers)->toHaveCount(3) // AAPL + NVDA (active) + TSLA (candidate)
        ->and($persona->candidateTickers->pluck('ticker')->toArray())->toBe(['TSLA']);
});

it('skips tickers that are already candidates', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create();
    $persona->tickers()->create([
        'ticker' => 'TSLA',
        'status' => TickerStatus::Candidate,
        'source' => TickerSource::AiDiscovered,
        'ai_rationale' => 'Previous suggestion',
    ]);

    $service = $this->mock(TickerDiscoveryService::class);
    $service->shouldReceive('suggest')->andReturn([
        ['ticker' => 'TSLA', 'rationale' => 'Duplicate suggestion'],
    ]);

    (new DiscoverTickersJob($persona))->handle($service);

    $persona->refresh();
    expect($persona->tickers->where('ticker', 'TSLA'))->toHaveCount(1);
});

it('handles empty suggestions gracefully', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create();

    $service = $this->mock(TickerDiscoveryService::class);
    $service->shouldReceive('suggest')->andReturn([]);

    (new DiscoverTickersJob($persona))->handle($service);

    expect($persona->fresh()->tickers)->toHaveCount(1); // unchanged
});
