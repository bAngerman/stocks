<?php

use App\Services\MarketDataService;
use App\Trading\MarketQuote;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

it('returns a MarketQuote DTO from a Finnhub quote response', function () {
    Http::fake([
        'finnhub.io/api/v1/quote*' => Http::response([
            'c' => 150.25,
            'dp' => 2.35,
            'h' => 152.0,
            'l' => 148.0,
            'o' => 149.0,
            'pc' => 147.0,
            't' => 1716000000,
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
        'finnhub.io/api/v1/quote?symbol=AAPL*' => Http::response([
            'c' => 150.0, 'dp' => 1.0, 'h' => 152.0, 'l' => 148.0, 'o' => 149.0, 'pc' => 148.5, 't' => 1716000000,
        ], 200),
        'finnhub.io/api/v1/quote?symbol=MSFT*' => Http::response([
            'c' => 420.0, 'dp' => -0.5, 'h' => 425.0, 'l' => 418.0, 'o' => 421.0, 'pc' => 422.0, 't' => 1716000000,
        ], 200),
    ]);

    $quotes = app(MarketDataService::class)->getQuotes(['AAPL', 'MSFT']);

    expect($quotes)->toHaveCount(2)
        ->and($quotes->first()->ticker)->toBe('AAPL')
        ->and($quotes->first()->price)->toBe(150.0)
        ->and($quotes->last()->ticker)->toBe('MSFT')
        ->and($quotes->last()->price)->toBe(420.0);
});

it('throws on HTTP failure for getQuote', function () {
    Http::fake([
        'finnhub.io/api/v1/quote*' => Http::response('', 500),
    ]);

    expect(fn () => app(MarketDataService::class)->getQuote('AAPL'))
        ->toThrow(RequestException::class);
});
