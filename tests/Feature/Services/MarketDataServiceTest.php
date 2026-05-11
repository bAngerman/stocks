<?php

use App\Services\MarketDataService;
use Illuminate\Support\Facades\Http;

// Minimal fake HTML containing the trending-tickers JSON blob
function fakeGainersHtml(array $tickers): string
{
    $items = array_map(fn ($t) => json_encode([
        'symbol' => $t['symbol'],
        'shortName' => $t['name'] ?? $t['symbol'],
        'quoteType' => $t['quoteType'] ?? 'EQUITY',
        'regularMarketChangePercent' => ['raw' => $t['changePercent'], 'fmt' => $t['changePercent'].'%'],
        'regularMarketPrice' => ['raw' => 100.0, 'fmt' => '100.00'],
    ]), $tickers);

    $json = '['.implode(',', $items).']';

    return '<script>trending-tickers">'.$json.'</script>';
}

it('returns top equity gainers sorted by change percent descending', function () {
    Http::fake([
        'finance.yahoo.com/*' => Http::response(fakeGainersHtml([
            ['symbol' => 'AAPL', 'changePercent' => 5.0],
            ['symbol' => 'NVDA', 'changePercent' => 25.0],
            ['symbol' => 'MSFT', 'changePercent' => 10.0],
        ]), 200),
    ]);

    $results = app(MarketDataService::class)->getGainers(limit: 3);

    expect($results)->toHaveCount(3)
        ->and($results->first()['ticker'])->toBe('NVDA')
        ->and($results->first()['changePercent'])->toBe(25.0)
        ->and($results->last()['ticker'])->toBe('AAPL');
});

it('filters out non-equity instruments', function () {
    Http::fake([
        'finance.yahoo.com/*' => Http::response(fakeGainersHtml([
            ['symbol' => 'NVDA', 'changePercent' => 20.0, 'quoteType' => 'EQUITY'],
            ['symbol' => 'BTC-USD', 'changePercent' => 50.0, 'quoteType' => 'CRYPTOCURRENCY'],
        ]), 200),
    ]);

    $results = app(MarketDataService::class)->getGainers();

    expect($results)->toHaveCount(1)
        ->and($results->first()['ticker'])->toBe('NVDA');
});

it('respects the limit parameter', function () {
    Http::fake([
        'finance.yahoo.com/*' => Http::response(fakeGainersHtml([
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
        'finance.yahoo.com/*' => Http::response('', 500),
    ]);

    expect(app(MarketDataService::class)->getGainers())->toBeEmpty();
});

it('returns empty collection when JSON cannot be parsed', function () {
    Http::fake([
        'finance.yahoo.com/*' => Http::response('<html>no data here</html>', 200),
    ]);

    expect(app(MarketDataService::class)->getGainers())->toBeEmpty();
});
