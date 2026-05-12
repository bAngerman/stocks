<?php

use App\Services\MarketDataService;
use Illuminate\Support\Facades\Http;

it('returns top gainers from watchlist sorted by change percent descending', function () {
    Http::fake([
        'finnhub.io/api/v1/quote?symbol=NVDA*' => Http::response(['c' => 900.0, 'dp' => 25.0, 'h' => 910.0, 'l' => 880.0, 'o' => 882.0, 'pc' => 720.0, 't' => 1716000000], 200),
        'finnhub.io/api/v1/quote?symbol=MSFT*' => Http::response(['c' => 420.0, 'dp' => 10.0, 'h' => 425.0, 'l' => 415.0, 'o' => 416.0, 'pc' => 381.0, 't' => 1716000000], 200),
        'finnhub.io/api/v1/quote?symbol=AAPL*' => Http::response(['c' => 150.0, 'dp' => 5.0, 'h' => 152.0, 'l' => 148.0, 'o' => 143.0, 'pc' => 142.5, 't' => 1716000000], 200),
    ]);

    config(['services.finnhub.watchlist' => ['NVDA', 'MSFT', 'AAPL']]);

    $results = app(MarketDataService::class)->getGainers(limit: 3);

    expect($results)->toHaveCount(3)
        ->and($results->first()['ticker'])->toBe('NVDA')
        ->and($results->first()['changePercent'])->toBe(25.0)
        ->and($results->last()['ticker'])->toBe('AAPL');
});

it('respects the limit parameter', function () {
    Http::fake([
        'finnhub.io/api/v1/quote?symbol=NVDA*' => Http::response(['c' => 900.0, 'dp' => 30.0, 'h' => 910.0, 'l' => 880.0, 'o' => 882.0, 'pc' => 692.0, 't' => 1716000000], 200),
        'finnhub.io/api/v1/quote?symbol=MSFT*' => Http::response(['c' => 420.0, 'dp' => 20.0, 'h' => 425.0, 'l' => 415.0, 'o' => 350.0, 'pc' => 350.0, 't' => 1716000000], 200),
        'finnhub.io/api/v1/quote?symbol=AAPL*' => Http::response(['c' => 150.0, 'dp' => 10.0, 'h' => 152.0, 'l' => 148.0, 'o' => 136.4, 'pc' => 136.4, 't' => 1716000000], 200),
        'finnhub.io/api/v1/quote?symbol=TSLA*' => Http::response(['c' => 200.0, 'dp' => 5.0, 'h' => 205.0, 'l' => 195.0, 'o' => 190.5, 'pc' => 190.5, 't' => 1716000000], 200),
    ]);

    config(['services.finnhub.watchlist' => ['NVDA', 'MSFT', 'AAPL', 'TSLA']]);

    $results = app(MarketDataService::class)->getGainers(limit: 2);

    expect($results)->toHaveCount(2)
        ->and($results->pluck('ticker')->toArray())->toBe(['NVDA', 'MSFT']);
});

it('returns empty collection when all quote requests fail', function () {
    Http::fake([
        'finnhub.io/api/v1/quote*' => Http::response('', 500),
    ]);

    config(['services.finnhub.watchlist' => ['AAPL']]);

    expect(app(MarketDataService::class)->getGainers())->toBeEmpty();
});

it('returns empty collection when watchlist is empty', function () {
    config(['services.finnhub.watchlist' => []]);

    expect(app(MarketDataService::class)->getGainers())->toBeEmpty();
});
