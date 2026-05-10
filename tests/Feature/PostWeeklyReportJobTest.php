<?php

use App\Jobs\PostWeeklyReportJob;
use App\Models\DiscordReport;
use App\Models\Persona;
use App\Models\Position;
use App\Models\PriceSnapshot;
use App\Models\Trade;
use App\Services\DiscordService;
use Illuminate\Support\Facades\Http;

it('posts a weekly summary to Discord and logs a DiscordReport', function () {
    Http::fake(['discord.com/*' => Http::response([], 200)]);

    $persona = Persona::factory()->create(['name' => 'Momentum Bot', 'cash_balance' => 8000.00]);
    Trade::factory()->for($persona)->buy()->create(['executed_at' => now()->subDays(3)]);
    Trade::factory()->for($persona)->sell()->create(['executed_at' => now()->subDays(2)]);
    Position::factory()->for($persona)->create([
        'ticker' => 'AAPL',
        'shares' => 5.0,
        'average_cost' => 140.00,
    ]);
    PriceSnapshot::factory()->forTicker('AAPL')->create(['price' => 150.00, 'fetched_at' => now()]);

    PostWeeklyReportJob::dispatchSync();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/messages'));
    expect(DiscordReport::count())->toBe(1);
});

it('includes each active persona in the report', function () {
    Http::fake(['discord.com/*' => Http::response([], 200)]);

    config(['services.discord.token' => 'token', 'services.discord.channel_id' => '123']);

    $personaA = Persona::factory()->create(['name' => 'Aggressive Bot']);
    $personaB = Persona::factory()->create(['name' => 'Conservative Bot']);
    Persona::factory()->inactive()->create(['name' => 'Inactive Bot']);

    PostWeeklyReportJob::dispatchSync();

    $report = DiscordReport::first();
    $payload = $report->payload;
    $fieldValues = collect($payload['embeds'][0]['fields'] ?? [])
        ->pluck('name')
        ->join(' ');

    expect($fieldValues)->toContain('Aggressive Bot')
        ->and($fieldValues)->toContain('Conservative Bot')
        ->and($fieldValues)->not->toContain('Inactive Bot');
});

it('does not log a DiscordReport if the Discord API call fails', function () {
    Http::fake(['discord.com/*' => Http::response(['message' => 'Error'], 500)]);

    Persona::factory()->create();

    expect(fn () => PostWeeklyReportJob::dispatchSync())->toThrow(\Exception::class);
    expect(DiscordReport::count())->toBe(0);
});
