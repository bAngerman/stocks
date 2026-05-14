<?php

use App\Models\Persona;
use App\Models\Trade;

it('backfills cost_basis on a sell trade using the average cost from prior buys', function () {
    $persona = Persona::factory()->create();

    Trade::factory()->for($persona)->buy()->create([
        'ticker' => 'NVDA',
        'shares' => 10,
        'price_per_share' => 100.00,
        'executed_at' => now()->subDays(3),
    ]);

    $sell = Trade::factory()->for($persona)->sell()->create([
        'ticker' => 'NVDA',
        'shares' => 5,
        'price_per_share' => 150.00,
        'cost_basis' => null,
        'executed_at' => now()->subDays(1),
    ]);

    $this->artisan('trades:backfill-cost-basis')->assertSuccessful();

    expect((float) $sell->fresh()->cost_basis)->toBe(100.0);
});

it('computes blended average cost across multiple buys before a sell', function () {
    $persona = Persona::factory()->create();

    Trade::factory()->for($persona)->buy()->create([
        'ticker' => 'AAPL', 'shares' => 4, 'price_per_share' => 100.00, 'executed_at' => now()->subDays(5),
    ]);
    Trade::factory()->for($persona)->buy()->create([
        'ticker' => 'AAPL', 'shares' => 6, 'price_per_share' => 200.00, 'executed_at' => now()->subDays(4),
    ]);

    $sell = Trade::factory()->for($persona)->sell()->create([
        'ticker' => 'AAPL', 'shares' => 3, 'price_per_share' => 250.00, 'cost_basis' => null, 'executed_at' => now()->subDays(3),
    ]);

    $this->artisan('trades:backfill-cost-basis')->assertSuccessful();

    // avg = (4 * 100 + 6 * 200) / 10 = 160.00
    expect((float) $sell->fresh()->cost_basis)->toBe(160.0);
});

it('resets average cost after position is fully closed then reopened', function () {
    $persona = Persona::factory()->create();

    Trade::factory()->for($persona)->buy()->create([
        'ticker' => 'TSLA', 'shares' => 5, 'price_per_share' => 100.00, 'executed_at' => now()->subDays(6),
    ]);
    Trade::factory()->for($persona)->sell()->create([
        'ticker' => 'TSLA', 'shares' => 5, 'price_per_share' => 120.00, 'cost_basis' => null, 'executed_at' => now()->subDays(5),
    ]);
    Trade::factory()->for($persona)->buy()->create([
        'ticker' => 'TSLA', 'shares' => 10, 'price_per_share' => 200.00, 'executed_at' => now()->subDays(4),
    ]);
    $secondSell = Trade::factory()->for($persona)->sell()->create([
        'ticker' => 'TSLA', 'shares' => 5, 'price_per_share' => 250.00, 'cost_basis' => null, 'executed_at' => now()->subDays(3),
    ]);

    $this->artisan('trades:backfill-cost-basis')->assertSuccessful();

    // Second sell should use cost from the second buy (200), not the first (100)
    expect((float) $secondSell->fresh()->cost_basis)->toBe(200.0);
});

it('does not overwrite sell trades that already have a cost_basis', function () {
    $persona = Persona::factory()->create();

    Trade::factory()->for($persona)->buy()->create([
        'ticker' => 'SPY', 'shares' => 10, 'price_per_share' => 100.00, 'executed_at' => now()->subDays(3),
    ]);
    $sell = Trade::factory()->for($persona)->sell()->create([
        'ticker' => 'SPY', 'shares' => 5, 'price_per_share' => 150.00, 'cost_basis' => 999.00, 'executed_at' => now()->subDays(1),
    ]);

    $this->artisan('trades:backfill-cost-basis')->assertSuccessful();

    expect((float) $sell->fresh()->cost_basis)->toBe(999.0);
});

it('reports no trades to backfill when all sells already have cost_basis', function () {
    $persona = Persona::factory()->create();
    Trade::factory()->for($persona)->sell()->create(['cost_basis' => 100.00]);

    $this->artisan('trades:backfill-cost-basis')
        ->assertSuccessful()
        ->expectsOutput('No sell trades need backfilling.');
});
