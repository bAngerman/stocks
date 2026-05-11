<?php

use App\Enums\TickerSource;
use App\Enums\TickerStatus;
use App\Jobs\SyncGainersJob;
use App\Models\Persona;
use App\Services\MarketDataService;
use Illuminate\Support\Collection;

function makeGainers(array $tickers): Collection
{
    return collect($tickers)->map(fn ($t) => [
        'ticker' => $t,
        'changePercent' => 10.0,
        'name' => $t,
    ]);
}

it('adds top gainers as candidate tickers for all active personas', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create();

    $mock = $this->mock(MarketDataService::class);
    $mock->shouldReceive('getGainers')->once()->andReturn(makeGainers(['NVDA', 'TSLA']));

    SyncGainersJob::dispatchSync();

    $persona->refresh();
    expect($persona->candidateTickers)->toHaveCount(2)
        ->and($persona->candidateTickers->pluck('ticker')->toArray())->toContain('NVDA', 'TSLA')
        ->and($persona->candidateTickers->first()->source)->toBe(TickerSource::GainersScan)
        ->and($persona->candidateTickers->first()->status)->toBe(TickerStatus::Candidate);
});

it('skips tickers already present for a persona', function () {
    $persona = Persona::factory()->withTickers(['NVDA'])->create();

    $mock = $this->mock(MarketDataService::class);
    $mock->shouldReceive('getGainers')->andReturn(makeGainers(['NVDA', 'TSLA']));

    SyncGainersJob::dispatchSync();

    $persona->refresh();
    expect($persona->tickers)->toHaveCount(2) // NVDA (active) + TSLA (candidate)
        ->and($persona->candidateTickers->pluck('ticker')->toArray())->toBe(['TSLA']);
});

it('does not add gainers to inactive personas', function () {
    $active = Persona::factory()->withTickers(['AAPL'])->create(['is_active' => true]);
    $inactive = Persona::factory()->withTickers(['AAPL'])->create(['is_active' => false]);

    $mock = $this->mock(MarketDataService::class);
    $mock->shouldReceive('getGainers')->andReturn(makeGainers(['NVDA']));

    SyncGainersJob::dispatchSync();

    expect($active->fresh()->tickers)->toHaveCount(2)
        ->and($inactive->fresh()->tickers)->toHaveCount(1);
});

it('handles empty gainers gracefully', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create();

    $mock = $this->mock(MarketDataService::class);
    $mock->shouldReceive('getGainers')->andReturn(collect());

    SyncGainersJob::dispatchSync();

    expect($persona->fresh()->tickers)->toHaveCount(1);
});
