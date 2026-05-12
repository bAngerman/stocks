# Polygon Market Data Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace `scheb/yahoo-finance-api` (hitting Yahoo Finance 429 rate limits) with Polygon.io's free REST API for both price quotes and the gainers list.

**Architecture:** All changes are contained within `MarketDataService` — the public interface (`getQuote`, `getQuotes`, `getGainers`) stays identical so no callers change. Quote fetching switches from the `ApiClient` wrapper to direct `Http` facade calls against Polygon's snapshot endpoints. The `scheb/yahoo-finance-api` package is removed once the implementation no longer references it.

**Tech Stack:** Laravel `Http` facade, Polygon.io REST API (free tier, 15-min delayed), Pest 4

---

## File Map

| File | Action | Purpose |
|------|--------|---------|
| `config/services.php` | Modify | Add `polygon.key` config entry |
| `.env` | Modify | Add `POLYGON_API_KEY=` |
| `.env.example` | Modify | Add `POLYGON_API_KEY=` |
| `app/Services/MarketDataService.php` | Modify | Replace `ApiClient` with `Http` facade + Polygon endpoints |
| `app/Providers/AppServiceProvider.php` | Modify | Remove `ApiClient` singleton, simplify `MarketDataService` binding |
| `composer.json` | Modify | Remove `scheb/yahoo-finance-api` |
| `tests/Feature/MarketDataServiceTest.php` | Rewrite | Mock Polygon quote endpoint via `Http::fake()` |
| `tests/Feature/Services/MarketDataServiceTest.php` | Rewrite | Mock Polygon gainers endpoint via `Http::fake()` |

---

## Task 1: Add Polygon config (no breaking changes)

**Files:**
- Modify: `config/services.php`
- Modify: `.env`
- Modify: `.env.example`

- [ ] **Step 1: Add `polygon` entry to `config/services.php`**

In `config/services.php`, add after the `discord` block:

```php
    'discord' => [
        'token' => env('DISCORD_BOT_TOKEN'),
        'channel_id' => env('DISCORD_CHANNEL_ID'),
    ],

    'polygon' => [
        'key' => env('POLYGON_API_KEY'),
    ],
```

- [ ] **Step 2: Add `POLYGON_API_KEY` to `.env` and `.env.example`**

In `.env`, append after `DISCORD_CHANNEL_ID=`:
```
POLYGON_API_KEY=
```

In `.env.example`, append after `DISCORD_CHANNEL_ID=`:
```
POLYGON_API_KEY=
```

Note: The user must register at [polygon.io](https://polygon.io) and paste their free-tier API key into `.env`.

- [ ] **Step 3: Run tests to confirm nothing broke yet**

```bash
php artisan test --compact
```

Expected: all tests pass (no implementation changes yet).

- [ ] **Step 4: Commit**

```bash
git add config/services.php .env.example
git commit -m "feat: add Polygon.io service config"
```

---

## Task 2: TDD — getQuote and getQuotes

**Files:**
- Rewrite: `tests/Feature/MarketDataServiceTest.php`
- Modify: `app/Services/MarketDataService.php`

- [ ] **Step 1: Replace the test file with Polygon-based tests**

Overwrite `tests/Feature/MarketDataServiceTest.php` entirely:

```php
<?php

use App\Services\MarketDataService;
use App\Trading\MarketQuote;
use Illuminate\Support\Facades\Http;

it('returns a MarketQuote DTO from a Polygon snapshot', function () {
    Http::fake([
        'api.polygon.io/*' => Http::response([
            'status' => 'OK',
            'ticker' => [
                'ticker' => 'AAPL',
                'todaysChangePerc' => 2.35,
                'lastTrade' => ['p' => 150.25],
                'day' => ['c' => 150.0],
            ],
        ], 200),
    ]);

    $quote = app(MarketDataService::class)->getQuote('AAPL');

    expect($quote)->toBeInstanceOf(MarketQuote::class)
        ->and($quote->ticker)->toBe('AAPL')
        ->and($quote->price)->toBe(150.25)
        ->and($quote->changePercent)->toBe(2.35);
});

it('returns a collection of MarketQuotes for multiple tickers', function () {
    Http::fake([
        'api.polygon.io/*/tickers/AAPL' => Http::response([
            'status' => 'OK',
            'ticker' => [
                'ticker' => 'AAPL',
                'todaysChangePerc' => 1.0,
                'lastTrade' => ['p' => 150.0],
                'day' => ['c' => 150.0],
            ],
        ], 200),
        'api.polygon.io/*/tickers/MSFT' => Http::response([
            'status' => 'OK',
            'ticker' => [
                'ticker' => 'MSFT',
                'todaysChangePerc' => -0.5,
                'lastTrade' => ['p' => 420.0],
                'day' => ['c' => 420.0],
            ],
        ], 200),
    ]);

    $quotes = app(MarketDataService::class)->getQuotes(['AAPL', 'MSFT']);

    expect($quotes)->toHaveCount(2)
        ->and($quotes->first()->ticker)->toBe('AAPL')
        ->and($quotes->first()->price)->toBe(150.0)
        ->and($quotes->last()->ticker)->toBe('MSFT')
        ->and($quotes->last()->price)->toBe(420.0);
});
```

- [ ] **Step 2: Run the new tests to confirm they fail**

```bash
php artisan test --compact --filter=MarketDataServiceTest
```

Expected: FAIL — the current implementation references `ApiClient` from the removed Yahoo package (or returns wrong data shape).

- [ ] **Step 3: Rewrite `MarketDataService` — constructor and quote methods**

Replace the full contents of `app/Services/MarketDataService.php`:

```php
<?php

namespace App\Services;

use App\Trading\MarketQuote;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MarketDataService
{
    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.polygon.key');
    }

    public function getQuote(string $ticker): MarketQuote
    {
        $response = Http::withToken($this->apiKey)
            ->get("https://api.polygon.io/v2/snapshot/locale/us/markets/stocks/tickers/{$ticker}")
            ->throw()
            ->json();

        $snapshot = $response['ticker'];

        return new MarketQuote(
            ticker: $ticker,
            price: (float) ($snapshot['lastTrade']['p'] ?? $snapshot['day']['c']),
            changePercent: (float) ($snapshot['todaysChangePerc'] ?? 0.0),
            fetchedAt: now(),
        );
    }

    /** @return Collection<int, MarketQuote> */
    public function getQuotes(array $tickers): Collection
    {
        return collect($tickers)->map(fn (string $ticker) => $this->getQuote($ticker));
    }

    /**
     * @return Collection<int, array{ticker: string, changePercent: float, name: string}>
     */
    public function getGainers(int $limit = 25): Collection
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(15)
                ->get('https://api.polygon.io/v2/snapshot/locale/us/markets/stocks/gainers');

            if ($response->failed()) {
                return collect();
            }

            return collect($response->json('tickers') ?? [])
                ->take($limit)
                ->map(fn (array $item) => [
                    'ticker' => $item['ticker'],
                    'changePercent' => (float) ($item['todaysChangePerc'] ?? 0.0),
                    'name' => $item['ticker'],
                ])
                ->values();
        } catch (\Throwable $e) {
            Log::warning('MarketDataService: getGainers failed', ['error' => $e->getMessage()]);

            return collect();
        }
    }
}
```

- [ ] **Step 4: Run the quote tests to confirm they pass**

```bash
php artisan test --compact --filter=MarketDataServiceTest
```

Expected: 2 tests PASS.

- [ ] **Step 5: Run pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit**

```bash
git add app/Services/MarketDataService.php tests/Feature/MarketDataServiceTest.php
git commit -m "feat: migrate getQuote/getQuotes to Polygon.io"
```

---

## Task 3: TDD — getGainers

**Files:**
- Rewrite: `tests/Feature/Services/MarketDataServiceTest.php`

- [ ] **Step 1: Replace the gainers test file with Polygon-based tests**

Overwrite `tests/Feature/Services/MarketDataServiceTest.php` entirely:

```php
<?php

use App\Services\MarketDataService;
use Illuminate\Support\Facades\Http;

function fakePolygonGainers(array $tickers): array
{
    return [
        'status' => 'OK',
        'tickers' => array_map(fn ($t) => [
            'ticker' => $t['symbol'],
            'todaysChangePerc' => $t['changePercent'],
            'lastTrade' => ['p' => 100.0],
        ], $tickers),
    ];
}

it('returns gainers mapped to ticker, changePercent, and name', function () {
    Http::fake([
        'api.polygon.io/*' => Http::response(fakePolygonGainers([
            ['symbol' => 'NVDA', 'changePercent' => 25.0],
            ['symbol' => 'MSFT', 'changePercent' => 10.0],
            ['symbol' => 'AAPL', 'changePercent' => 5.0],
        ]), 200),
    ]);

    $results = app(MarketDataService::class)->getGainers(limit: 3);

    expect($results)->toHaveCount(3)
        ->and($results->first()['ticker'])->toBe('NVDA')
        ->and($results->first()['changePercent'])->toBe(25.0)
        ->and($results->last()['ticker'])->toBe('AAPL');
});

it('respects the limit parameter', function () {
    Http::fake([
        'api.polygon.io/*' => Http::response(fakePolygonGainers([
            ['symbol' => 'A', 'changePercent' => 30.0],
            ['symbol' => 'B', 'changePercent' => 20.0],
            ['symbol' => 'C', 'changePercent' => 10.0],
            ['symbol' => 'D', 'changePercent' => 5.0],
        ]), 200),
    ]);

    $results = app(MarketDataService::class)->getGainers(limit: 2);

    expect($results)->toHaveCount(2)
        ->and($results->pluck('ticker')->toArray())->toBe(['A', 'B']);
});

it('returns empty collection on HTTP failure', function () {
    Http::fake([
        'api.polygon.io/*' => Http::response('Server Error', 500),
    ]);

    expect(app(MarketDataService::class)->getGainers())->toBeEmpty();
});

it('returns empty collection when response has no tickers key', function () {
    Http::fake([
        'api.polygon.io/*' => Http::response(['status' => 'ERROR'], 200),
    ]);

    expect(app(MarketDataService::class)->getGainers())->toBeEmpty();
});
```

- [ ] **Step 2: Run the gainers tests**

`getGainers()` was already written as part of the full file replacement in Task 2 Step 3, so these tests should pass immediately:

```bash
php artisan test --compact --filter="Feature/Services/MarketDataServiceTest"
```

Expected: 4 tests PASS. If any fail, verify `MarketDataService.php` matches the code from Task 2 Step 3 exactly (particularly the `getGainers` method using `$response->json('tickers') ?? []`).

- [ ] **Step 4: Run pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Services/MarketDataServiceTest.php
git commit -m "test: rewrite gainers tests for Polygon.io"
```

---

## Task 4: Remove Yahoo Finance package and clean up AppServiceProvider

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `composer.json` (via `composer remove`)

- [ ] **Step 1: Remove the Yahoo Finance package**

```bash
composer remove scheb/yahoo-finance-api
```

Expected output includes: `Removing scheb/yahoo-finance-api`

- [ ] **Step 2: Update `AppServiceProvider` to remove the Yahoo Finance bindings**

Replace the full contents of `app/Providers/AppServiceProvider.php`:

```php
<?php

namespace App\Providers;

use App\Services\DiscordService;
use App\Services\MarketDataService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MarketDataService::class, fn () => new MarketDataService());

        $this->app->singleton(DiscordService::class, fn ($app) => new DiscordService(
            token: $app['config']['services.discord.token'],
            channelId: $app['config']['services.discord.channel_id'],
        ));
    }
}
```

- [ ] **Step 3: Run the full test suite**

```bash
php artisan test --compact
```

Expected: all tests pass, no references to `Scheb\YahooFinanceApi` remain.

- [ ] **Step 4: Run pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 5: Commit**

```bash
git add app/Providers/AppServiceProvider.php composer.json composer.lock
git commit -m "feat: remove scheb/yahoo-finance-api, complete Polygon.io migration"
```

---

## Post-implementation note

After the plan is complete, the user must add their Polygon.io API key to `.env`:

```
POLYGON_API_KEY=your_key_here
```

Free tier keys are available at [polygon.io](https://polygon.io) with no credit card required.
