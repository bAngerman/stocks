<?php

use App\Enums\TickerSource;
use App\Enums\TickerStatus;
use App\Jobs\SyncGainersJob;
use App\Models\Persona;
use App\Models\PersonaTicker;
use App\Services\MarketDataService;
use App\Services\TickerDiscoveryService;
use App\Trading\MarketQuote;

function makePool(array $tickers): array
{
    return collect($tickers)->map(fn ($t) => [
        'ticker' => $t,
        'name' => $t,
        'rationale' => "Reason for {$t}",
    ])->values()->all();
}

function makeQuote(string $ticker, float $price = 100.0): MarketQuote
{
    return new MarketQuote($ticker, $price, 1.0, now());
}

it('persists assigned tickers with AiDiscovered source and rationale', function () {
    $persona = Persona::factory()->create(['name' => 'Momentum Mike']);
    $pool = makePool(['NVDA', 'TSLA']);

    $discovery = $this->mock(TickerDiscoveryService::class);
    $discovery->shouldReceive('discoverPool')->once()->andReturn($pool);
    $discovery->shouldReceive('assignToPersonas')->once()->andReturn([
        'Momentum Mike' => ['NVDA', 'TSLA'],
    ]);

    $market = $this->mock(MarketDataService::class);
    $market->shouldReceive('getQuote')->with('NVDA')->andReturn(makeQuote('NVDA'));
    $market->shouldReceive('getQuote')->with('TSLA')->andReturn(makeQuote('TSLA'));

    SyncGainersJob::dispatchSync();

    $candidates = $persona->fresh()->candidateTickers;
    expect($candidates)->toHaveCount(2)
        ->and($candidates->firstWhere('ticker', 'NVDA')->source)->toBe(TickerSource::AiDiscovered)
        ->and($candidates->firstWhere('ticker', 'NVDA')->ai_rationale)->toBe('Reason for NVDA');
});

it('does not persist tickers that fail Finnhub validation', function () {
    $persona = Persona::factory()->create(['name' => 'Test Persona']);
    $pool = makePool(['REAL', 'FAKE']);

    $discovery = $this->mock(TickerDiscoveryService::class);
    $discovery->shouldReceive('discoverPool')->andReturn($pool);
    $discovery->shouldReceive('assignToPersonas')->andReturn([
        'Test Persona' => ['REAL', 'FAKE'],
    ]);

    $market = $this->mock(MarketDataService::class);
    $market->shouldReceive('getQuote')->with('REAL')->andReturn(makeQuote('REAL', 150.0));
    $market->shouldReceive('getQuote')->with('FAKE')->andThrow(new Exception('Not found'));

    SyncGainersJob::dispatchSync();

    $candidates = $persona->fresh()->candidateTickers;
    expect($candidates)->toHaveCount(1)
        ->and($candidates->first()->ticker)->toBe('REAL');
});

it('prunes candidate tickers older than the configured TTL', function () {
    config(['trading.candidate_ttl_days' => 7]);
    $persona = Persona::factory()->create(['name' => 'Test Persona']);

    $stale = $persona->tickers()->create([
        'ticker' => 'OLD',
        'status' => TickerStatus::Candidate,
        'source' => TickerSource::AiDiscovered,
    ]);
    PersonaTicker::where('id', $stale->id)->update(['created_at' => now()->subDays(8)]);

    $fresh = $persona->tickers()->create([
        'ticker' => 'FRESH',
        'status' => TickerStatus::Candidate,
        'source' => TickerSource::AiDiscovered,
    ]);

    $discovery = $this->mock(TickerDiscoveryService::class);
    $discovery->shouldReceive('discoverPool')->andReturn([]);
    $this->mock(MarketDataService::class);

    SyncGainersJob::dispatchSync();

    expect(PersonaTicker::find($stale->id))->toBeNull()
        ->and(PersonaTicker::find($fresh->id))->not->toBeNull();
});

it('does not prune active tickers regardless of age', function () {
    config(['trading.candidate_ttl_days' => 7]);
    $persona = Persona::factory()->withTickers(['AAPL'])->create();
    $active = $persona->tickers()->first();
    PersonaTicker::where('id', $active->id)->update(['created_at' => now()->subDays(30)]);

    $discovery = $this->mock(TickerDiscoveryService::class);
    $discovery->shouldReceive('discoverPool')->andReturn([]);
    $this->mock(MarketDataService::class);

    SyncGainersJob::dispatchSync();

    expect(PersonaTicker::find($active->id))->not->toBeNull();
});

it('returns early when pool is empty without making assignments', function () {
    $persona = Persona::factory()->create(['name' => 'Test Persona']);

    $discovery = $this->mock(TickerDiscoveryService::class);
    $discovery->shouldReceive('discoverPool')->once()->andReturn([]);
    $discovery->shouldReceive('assignToPersonas')->never();

    $this->mock(MarketDataService::class)->shouldNotReceive('getQuote');

    SyncGainersJob::dispatchSync();

    expect($persona->fresh()->candidateTickers)->toHaveCount(0);
});

it('does not assign tickers to inactive personas', function () {
    $active = Persona::factory()->create(['name' => 'Active Persona']);
    Persona::factory()->create(['name' => 'Inactive Persona', 'is_active' => false]);
    $pool = makePool(['NVDA']);

    $discovery = $this->mock(TickerDiscoveryService::class);
    $discovery->shouldReceive('discoverPool')->andReturn($pool);
    $discovery->shouldReceive('assignToPersonas')->andReturn(['Active Persona' => ['NVDA']]);

    $market = $this->mock(MarketDataService::class);
    $market->shouldReceive('getQuote')->with('NVDA')->andReturn(makeQuote('NVDA'));

    SyncGainersJob::dispatchSync();

    expect($active->fresh()->candidateTickers)->toHaveCount(1)
        ->and(Persona::where('name', 'Inactive Persona')->first()->tickers)->toHaveCount(0);
});

it('skips tickers already present for a persona', function () {
    $persona = Persona::factory()->withTickers(['NVDA'])->create(['name' => 'Test Persona']);
    $pool = makePool(['NVDA', 'TSLA']);

    $discovery = $this->mock(TickerDiscoveryService::class);
    $discovery->shouldReceive('discoverPool')->andReturn($pool);
    $discovery->shouldReceive('assignToPersonas')->andReturn([
        'Test Persona' => ['NVDA', 'TSLA'],
    ]);

    $market = $this->mock(MarketDataService::class);
    $market->shouldReceive('getQuote')->with('NVDA')->andReturn(makeQuote('NVDA'));
    $market->shouldReceive('getQuote')->with('TSLA')->andReturn(makeQuote('TSLA'));

    SyncGainersJob::dispatchSync();

    expect($persona->fresh()->tickers)->toHaveCount(2);
});
