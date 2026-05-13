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

    Http::assertSent(fn ($request) => $request->url() === 'https://api.anthropic.com/v1/messages' &&
        $request->hasHeader('x-api-key')
    );
});

it('strips markdown fences from pool response', function () {
    $pool = [['ticker' => 'AAPL', 'name' => 'Apple Inc', 'rationale' => 'Stable']];

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['text' => "```json\n".json_encode($pool)."\n```"]],
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
