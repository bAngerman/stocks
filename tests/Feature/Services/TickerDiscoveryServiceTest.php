<?php

use App\Models\Persona;
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

    Http::assertSent(fn ($request) => $request->url() === 'https://api.anthropic.com/v1/messages'
        && $request->hasHeader('x-api-key'));
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
