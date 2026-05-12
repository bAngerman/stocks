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
