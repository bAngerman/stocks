# AI Ticker Discovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the static `FINNHUB_WATCHLIST` config with two-phase AI-driven ticker discovery — Claude suggests a pool of candidates, then assigns them to personas by strategy fit.

**Architecture:** A new `TickerDiscoveryService` wraps two Claude API calls (discover pool, then assign to personas). `SyncGainersJob` orchestrates: prune stale candidates → discover → assign → validate against Finnhub → persist. `MarketDataService` loses its `$watchlist` property and `getGainers()` method entirely.

**Tech Stack:** Laravel 13, Pest 4, Finnhub REST API, Anthropic API (`claude-sonnet-4-6`)

---

## File Map

| Action | File | Responsibility |
|---|---|---|
| Create | `app/Services/TickerDiscoveryService.php` | Two-phase Claude calls: pool discovery + persona assignment |
| Create | `tests/Feature/Services/TickerDiscoveryServiceTest.php` | Unit tests for both phases |
| Create | `config/trading.php` | `candidate_ttl_days` config |
| Modify | `app/Jobs/SyncGainersJob.php` | Orchestrate prune → discover → assign → validate → persist |
| Modify | `tests/Feature/Jobs/SyncGainersJobTest.php` | Replace all tests with new contract |
| Modify | `app/Services/MarketDataService.php` | Remove `$watchlist` and `getGainers()` |
| Modify | `config/services.php` | Remove `finnhub.watchlist` key |
| Modify | `.env.example` | Remove `FINNHUB_WATCHLIST`, add `CANDIDATE_TTL_DAYS` |
| Delete | `tests/Feature/Services/MarketDataServiceTest.php` | `getGainers()` tests — feature removed |

---

## Task 1: Create `TickerDiscoveryService` — `discoverPool()`

**Files:**
- Create: `app/Services/TickerDiscoveryService.php`
- Create: `tests/Feature/Services/TickerDiscoveryServiceTest.php`

- [ ] **Step 1: Create the test file with failing tests for `discoverPool()`**

Create `tests/Feature/Services/TickerDiscoveryServiceTest.php`:

```php
<?php

use App\Services\TickerDiscoveryService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

it('returns a pool of ticker candidates from Claude', function () {
    $pool = [
        ['ticker' => 'NVDA', 'name' => 'NVIDIA Corp', 'rationale' => 'AI momentum'],
        ['ticker' => 'TSLA', 'name' => 'Tesla Inc', 'rationale' => 'High volatility'],
    ];

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['text' => json_encode($pool)]],
        ]),
    ]);

    $result = app(TickerDiscoveryService::class)->discoverPool(2);

    expect($result)->toHaveCount(2)
        ->and($result[0]['ticker'])->toBe('NVDA')
        ->and($result[0]['rationale'])->toBe('AI momentum')
        ->and($result[1]['ticker'])->toBe('TSLA');
});

it('strips markdown fences from pool response', function () {
    $pool = [['ticker' => 'AAPL', 'name' => 'Apple Inc', 'rationale' => 'Stable']];

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['text' => "```json\n" . json_encode($pool) . "\n```"]],
        ]),
    ]);

    $result = app(TickerDiscoveryService::class)->discoverPool();

    expect($result)->toHaveCount(1)->and($result[0]['ticker'])->toBe('AAPL');
});

it('returns empty array and logs warning when pool response is unparseable', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['text' => 'not valid json']],
        ]),
    ]);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $msg) => str_contains($msg, 'unparseable pool response'));

    expect(app(TickerDiscoveryService::class)->discoverPool())->toBeEmpty();
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact --filter=TickerDiscoveryService
```

Expected: FAIL — `TickerDiscoveryService` class not found.

- [ ] **Step 3: Create `TickerDiscoveryService` with `discoverPool()`**

Create `app/Services/TickerDiscoveryService.php`:

```php
<?php

namespace App\Services;

use App\Models\Persona;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TickerDiscoveryService
{
    /**
     * @return array<int, array{ticker: string, name: string, rationale: string}>
     */
    public function discoverPool(int $count = 25): array
    {
        $response = Http::withHeaders([
            'x-api-key' => config('services.anthropic.key'),
            'anthropic-version' => config('services.anthropic.version'),
            'content-type' => 'application/json',
        ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
            'model' => config('services.anthropic.model'),
            'max_tokens' => 1024,
            'messages' => [['role' => 'user', 'content' => $this->buildDiscoveryPrompt($count)]],
        ]);

        $response->throw();

        $text = $response->json('content.0.text', '');
        $text = preg_replace('/^```(?:\w+)?\n?|\n?```$/s', '', trim($text));
        $parsed = json_decode($text, true);

        if (! is_array($parsed)) {
            Log::warning('TickerDiscoveryService: unparseable pool response', ['response' => $text]);

            return [];
        }

        return $parsed;
    }

    private function buildDiscoveryPrompt(int $count): string
    {
        return <<<PROMPT
You are assisting an automated paper trading system. Suggest exactly {$count} US stock and ETF tickers worth watching for active intraday trading today. Focus on stocks with strong momentum, high volume, or notable volatility. Stocks and ETFs only — no options, no crypto.

Return a JSON array only — no other text:
[{"ticker": "SYMBOL", "name": "Company Name", "rationale": "Brief reason"}, ...]
PROMPT;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test --compact --filter=TickerDiscoveryService
```

Expected: 3 tests pass.

- [ ] **Step 5: Format**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit**

```bash
git add app/Services/TickerDiscoveryService.php tests/Feature/Services/TickerDiscoveryServiceTest.php
git commit -m "feat: add TickerDiscoveryService with discoverPool()"
```

---

## Task 2: Add `assignToPersonas()` to `TickerDiscoveryService`

**Files:**
- Modify: `app/Services/TickerDiscoveryService.php`
- Modify: `tests/Feature/Services/TickerDiscoveryServiceTest.php`

- [ ] **Step 1: Add failing tests for `assignToPersonas()`**

First, add `use App\Models\Persona;` to the import block at the top of `tests/Feature/Services/TickerDiscoveryServiceTest.php` (alongside the existing `use` statements).

Then append the following tests to the bottom of that file:

```php
it('returns persona-to-ticker assignment map from Claude', function () {
    $pool = [
        ['ticker' => 'NVDA', 'name' => 'NVIDIA', 'rationale' => 'AI momentum'],
        ['ticker' => 'SPY', 'name' => 'S&P 500 ETF', 'rationale' => 'Mean reversion target'],
    ];
    $personas = Persona::factory()->count(2)->sequence(
        ['name' => 'Momentum Mike'],
        ['name' => 'Mean Reversion Sally'],
    )->create();

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['text' => json_encode([
                'Momentum Mike' => ['NVDA'],
                'Mean Reversion Sally' => ['SPY'],
            ])]],
        ]),
    ]);

    $result = app(TickerDiscoveryService::class)->assignToPersonas($pool, $personas);

    expect($result)->toHaveKey('Momentum Mike')
        ->and($result['Momentum Mike'])->toBe(['NVDA'])
        ->and($result['Mean Reversion Sally'])->toBe(['SPY']);
});

it('strips markdown fences from assignment response', function () {
    $pool = [['ticker' => 'AAPL', 'name' => 'Apple', 'rationale' => 'Stable']];
    $personas = Persona::factory()->create(['name' => 'Test Persona']);
    $json = json_encode(['Test Persona' => ['AAPL']]);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['text' => "```json\n{$json}\n```"]],
        ]),
    ]);

    $result = app(TickerDiscoveryService::class)->assignToPersonas($pool, collect([$personas]));

    expect($result)->toHaveKey('Test Persona')->and($result['Test Persona'])->toBe(['AAPL']);
});

it('returns empty array and logs warning when assignment response is unparseable', function () {
    $pool = [['ticker' => 'AAPL', 'name' => 'Apple', 'rationale' => 'Stable']];
    $personas = Persona::factory()->count(1)->create();

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['text' => 'not json']],
        ]),
    ]);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $msg) => str_contains($msg, 'unparseable assignment response'));

    expect(app(TickerDiscoveryService::class)->assignToPersonas($pool, $personas))->toBeEmpty();
});

it('returns empty array without calling Claude when pool is empty', function () {
    Http::fake();
    $personas = Persona::factory()->count(1)->create();

    $result = app(TickerDiscoveryService::class)->assignToPersonas([], $personas);

    expect($result)->toBeEmpty();
    Http::assertNothingSent();
});

it('returns empty array without calling Claude when personas collection is empty', function () {
    Http::fake();
    $pool = [['ticker' => 'AAPL', 'name' => 'Apple', 'rationale' => 'Stable']];

    $result = app(TickerDiscoveryService::class)->assignToPersonas($pool, collect());

    expect($result)->toBeEmpty();
    Http::assertNothingSent();
});
```

- [ ] **Step 2: Run tests to verify new ones fail**

```bash
php artisan test --compact --filter=TickerDiscoveryService
```

Expected: 3 pass, 5 fail — `assignToPersonas` method not found.

- [ ] **Step 3: Add `assignToPersonas()` and its prompt to `TickerDiscoveryService`**

Add after the `buildDiscoveryPrompt()` method in `app/Services/TickerDiscoveryService.php`:

```php
/**
 * @param array<int, array{ticker: string, name: string, rationale: string}> $pool
 * @param Collection<int, Persona> $personas
 * @return array<string, string[]>
 */
public function assignToPersonas(array $pool, Collection $personas): array
{
    if (empty($pool) || $personas->isEmpty()) {
        return [];
    }

    $response = Http::withHeaders([
        'x-api-key' => config('services.anthropic.key'),
        'anthropic-version' => config('services.anthropic.version'),
        'content-type' => 'application/json',
    ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
        'model' => config('services.anthropic.model'),
        'max_tokens' => 512,
        'messages' => [['role' => 'user', 'content' => $this->buildAssignmentPrompt($pool, $personas)]],
    ]);

    $response->throw();

    $text = $response->json('content.0.text', '');
    $text = preg_replace('/^```(?:\w+)?\n?|\n?```$/s', '', trim($text));
    $parsed = json_decode($text, true);

    if (! is_array($parsed)) {
        Log::warning('TickerDiscoveryService: unparseable assignment response', ['response' => $text]);

        return [];
    }

    return $parsed;
}

private function buildAssignmentPrompt(array $pool, Collection $personas): string
{
    $poolList = collect($pool)
        ->map(fn (array $t) => "- {$t['ticker']}: {$t['rationale']}")
        ->implode("\n");

    $personaList = $personas
        ->map(fn (Persona $p) => "- {$p->name} ({$p->strategy_type->value}): {$p->description}")
        ->implode("\n");

    return <<<PROMPT
You are assigning stock tickers to trading personas for a paper trading system.

Available tickers (only assign from this list):
{$poolList}

Personas:
{$personaList}

For each persona, select the tickers from the list above that best match their strategy. Only assign tickers that appear in the available list above.

Return a JSON object only — no other text:
{"Persona Name": ["TICKER1", "TICKER2"], ...}
PROMPT;
}
```

- [ ] **Step 4: Run tests to verify all pass**

```bash
php artisan test --compact --filter=TickerDiscoveryService
```

Expected: 8 tests pass.

- [ ] **Step 5: Format**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit**

```bash
git add app/Services/TickerDiscoveryService.php tests/Feature/Services/TickerDiscoveryServiceTest.php
git commit -m "feat: add assignToPersonas() to TickerDiscoveryService"
```

---

## Task 3: Rewrite `SyncGainersJob` and add `config/trading.php`

**Files:**
- Create: `config/trading.php`
- Modify: `app/Jobs/SyncGainersJob.php`
- Modify: `tests/Feature/Jobs/SyncGainersJobTest.php`

- [ ] **Step 1: Create `config/trading.php`**

```php
<?php

return [
    'candidate_ttl_days' => env('CANDIDATE_TTL_DAYS', 7),
];
```

- [ ] **Step 2: Replace `tests/Feature/Jobs/SyncGainersJobTest.php` with new tests**

```php
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
    $discovery->shouldReceive('discoverPool')->andReturn([]);
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
```

- [ ] **Step 3: Run tests to verify they fail**

```bash
php artisan test --compact --filter=SyncGainersJob
```

Expected: FAIL — `makePool` function already defined error or contract mismatch. The tests will fail because the job still uses `getGainers()`.

- [ ] **Step 4: Rewrite `app/Jobs/SyncGainersJob.php`**

```php
<?php

namespace App\Jobs;

use App\Enums\TickerSource;
use App\Enums\TickerStatus;
use App\Models\Persona;
use App\Models\PersonaTicker;
use App\Services\MarketDataService;
use App\Services\TickerDiscoveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncGainersJob implements ShouldQueue
{
    use Queueable;

    public function handle(TickerDiscoveryService $discovery, MarketDataService $marketData): void
    {
        Log::info('SyncGainersJob: starting');

        $ttl = (int) config('trading.candidate_ttl_days', 7);
        $pruned = PersonaTicker::where('status', TickerStatus::Candidate)
            ->where('created_at', '<', now()->subDays($ttl))
            ->delete();
        Log::info('SyncGainersJob: pruned stale candidates', ['count' => $pruned]);

        $personas = Persona::where('is_active', true)->get();
        if ($personas->isEmpty()) {
            Log::warning('SyncGainersJob: no active personas');

            return;
        }

        $pool = $discovery->discoverPool();
        if (empty($pool)) {
            Log::warning('SyncGainersJob: empty pool from discovery');

            return;
        }

        $assignments = $discovery->assignToPersonas($pool, $personas);
        if (empty($assignments)) {
            Log::warning('SyncGainersJob: empty assignments from discovery');

            return;
        }

        $allTickers = collect($assignments)->flatten()->unique()->values()->all();
        $validTickers = collect($allTickers)->filter(function (string $ticker) use ($marketData) {
            try {
                return $marketData->getQuote($ticker)->price > 0;
            } catch (Throwable $e) {
                Log::warning('SyncGainersJob: ticker validation failed', [
                    'ticker' => $ticker,
                    'error' => $e->getMessage(),
                ]);

                return false;
            }
        })->all();

        $rationales = collect($pool)->keyBy('ticker');
        $personaMap = $personas->keyBy('name');
        $tickersAdded = 0;

        foreach ($assignments as $personaName => $tickers) {
            $persona = $personaMap->get($personaName);
            if (! $persona) {
                continue;
            }

            foreach ($tickers as $ticker) {
                if (! in_array($ticker, $validTickers)) {
                    continue;
                }

                $row = $persona->tickers()->firstOrCreate(
                    ['ticker' => $ticker],
                    [
                        'status' => TickerStatus::Candidate,
                        'source' => TickerSource::AiDiscovered,
                        'ai_rationale' => $rationales->get($ticker)['rationale'] ?? null,
                    ]
                );

                if ($row->wasRecentlyCreated) {
                    $tickersAdded++;
                }
            }
        }

        Log::info('SyncGainersJob: completed', [
            'pool_size' => count($pool),
            'valid_tickers' => count($validTickers),
            'tickers_added' => $tickersAdded,
            'personas_processed' => count($assignments),
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('SyncGainersJob: failed', ['error' => $e->getMessage()]);
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
php artisan test --compact --filter=SyncGainersJob
```

Expected: 7 tests pass.

- [ ] **Step 6: Format**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 7: Commit**

```bash
git add config/trading.php app/Jobs/SyncGainersJob.php tests/Feature/Jobs/SyncGainersJobTest.php
git commit -m "feat: rewrite SyncGainersJob with AI-driven discovery via TickerDiscoveryService"
```

---

## Task 4: Remove `getGainers()` from `MarketDataService` and clean up config

**Files:**
- Modify: `app/Services/MarketDataService.php`
- Modify: `config/services.php`
- Modify: `.env.example`
- Delete: `tests/Feature/Services/MarketDataServiceTest.php`

- [ ] **Step 1: Delete the `getGainers()` test file**

```bash
rm tests/Feature/Services/MarketDataServiceTest.php
```

- [ ] **Step 2: Remove `$watchlist` and `getGainers()` from `MarketDataService`**

Replace the entire `app/Services/MarketDataService.php` with:

```php
<?php

namespace App\Services;

use App\Trading\MarketQuote;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class MarketDataService
{
    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.finnhub.key');
    }

    public function getQuote(string $ticker): MarketQuote
    {
        $response = Http::withHeaders(['X-Finnhub-Token' => $this->apiKey])
            ->get('https://finnhub.io/api/v1/quote', ['symbol' => $ticker])
            ->throw()
            ->json();

        return new MarketQuote(
            ticker: $ticker,
            price: (float) $response['c'],
            changePercent: (float) $response['dp'],
            fetchedAt: now(),
        );
    }

    /** @return Collection<int, MarketQuote> */
    public function getQuotes(array $tickers): Collection
    {
        return collect($tickers)->map(fn (string $ticker) => $this->getQuote($ticker));
    }
}
```

- [ ] **Step 3: Remove `finnhub.watchlist` from `config/services.php`**

Replace the `finnhub` block:

```php
'finnhub' => [
    'key' => env('FINNHUB_API_KEY'),
],
```

- [ ] **Step 4: Update `.env.example`**

Remove `FINNHUB_WATCHLIST=...` and add `CANDIDATE_TTL_DAYS=7`. The relevant section should look like:

```
FINNHUB_API_KEY=
CANDIDATE_TTL_DAYS=7
```

- [ ] **Step 5: Run the full test suite**

```bash
php artisan test --compact
```

Expected: All tests pass. The deleted `getGainers` tests are gone; `tests/Feature/MarketDataServiceTest.php` (which tests `getQuote` and `getQuotes`) still passes.

- [ ] **Step 6: Format**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 7: Commit**

```bash
git add app/Services/MarketDataService.php config/services.php .env.example
git rm tests/Feature/Services/MarketDataServiceTest.php
git commit -m "feat: remove static watchlist from MarketDataService, clean up config"
```
