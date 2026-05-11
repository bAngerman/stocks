<?php

use App\Enums\StrategyType;
use App\Models\Persona;
use App\Services\TickerDiscoveryService;
use Illuminate\Support\Facades\Http;

it('returns parsed ticker suggestions from a valid response', function () {
    Http::fake([
        'https://api.anthropic.com/*' => Http::response(json_encode([
            'content' => [[
                'text' => '[{"ticker":"NVDA","rationale":"Strong AI chip demand"},{"ticker":"META","rationale":"Ad revenue recovery"}]',
            ]],
        ]), 200),
    ]);

    $persona = Persona::factory()->withTickers(['AAPL'])->create([
        'strategy_type' => StrategyType::Momentum,
    ]);

    $results = (new TickerDiscoveryService)->suggest($persona);

    expect($results)->toHaveCount(2)
        ->and($results[0]['ticker'])->toBe('NVDA')
        ->and($results[0]['rationale'])->toBe('Strong AI chip demand')
        ->and($results[1]['ticker'])->toBe('META');
});

it('returns empty array when the API returns a non-JSON response', function () {
    Http::fake([
        'https://api.anthropic.com/*' => Http::response(json_encode([
            'content' => [['text' => 'I cannot provide stock recommendations.']],
        ]), 200),
    ]);

    $persona = Persona::factory()->withTickers(['AAPL'])->create();

    expect((new TickerDiscoveryService)->suggest($persona))->toBe([]);
});

it('returns empty array when the API call fails', function () {
    Http::fake([
        'https://api.anthropic.com/*' => Http::response('', 500),
    ]);

    $persona = Persona::factory()->withTickers(['AAPL'])->create();

    expect((new TickerDiscoveryService)->suggest($persona))->toBe([]);
});

it('normalises ticker symbols to uppercase', function () {
    Http::fake([
        'https://api.anthropic.com/*' => Http::response(json_encode([
            'content' => [[
                'text' => '[{"ticker":"nvda","rationale":"test"}]',
            ]],
        ]), 200),
    ]);

    $persona = Persona::factory()->withTickers(['AAPL'])->create();

    $results = (new TickerDiscoveryService)->suggest($persona);

    expect($results[0]['ticker'])->toBe('NVDA');
});
