<?php

use App\Services\MarketDataService;
use App\Trading\MarketQuote;
use Scheb\YahooFinanceApi\ApiClient;
use Scheb\YahooFinanceApi\Results\Quote;

it('returns a MarketQuote DTO from a Yahoo Finance quote', function () {
    $mockQuote = Mockery::mock(Quote::class);
    $mockQuote->shouldReceive('getRegularMarketPrice')->andReturn(150.25);
    $mockQuote->shouldReceive('getRegularMarketChangePercent')->andReturn(2.35);

    $mockClient = Mockery::mock(ApiClient::class);
    $mockClient->shouldReceive('getQuote')->with('AAPL')->andReturn($mockQuote);

    $this->app->instance(ApiClient::class, $mockClient);

    $quote = app(MarketDataService::class)->getQuote('AAPL');

    expect($quote)->toBeInstanceOf(MarketQuote::class)
        ->and($quote->ticker)->toBe('AAPL')
        ->and($quote->price)->toBe(150.25)
        ->and($quote->changePercent)->toBe(2.35);
});

it('returns a collection of MarketQuotes for multiple tickers', function () {
    $mockAapl = Mockery::mock(Quote::class);
    $mockAapl->shouldReceive('getRegularMarketPrice')->andReturn(150.0);
    $mockAapl->shouldReceive('getRegularMarketChangePercent')->andReturn(1.0);

    $mockMsft = Mockery::mock(Quote::class);
    $mockMsft->shouldReceive('getRegularMarketPrice')->andReturn(420.0);
    $mockMsft->shouldReceive('getRegularMarketChangePercent')->andReturn(-0.5);

    $mockClient = Mockery::mock(ApiClient::class);
    $mockClient->shouldReceive('getQuote')->with('AAPL')->andReturn($mockAapl);
    $mockClient->shouldReceive('getQuote')->with('MSFT')->andReturn($mockMsft);

    $this->app->instance(ApiClient::class, $mockClient);

    $quotes = app(MarketDataService::class)->getQuotes(['AAPL', 'MSFT']);

    expect($quotes)->toHaveCount(2)
        ->and($quotes->first()->ticker)->toBe('AAPL')
        ->and($quotes->last()->ticker)->toBe('MSFT');
});
