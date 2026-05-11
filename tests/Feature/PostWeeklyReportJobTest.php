<?php

use App\Jobs\PostWeeklyReportJob;
use App\Models\DiscordReport;
use App\Models\Persona;
use App\Models\PersonaPortfolioSnapshot;
use App\Models\Position;
use App\Models\PriceSnapshot;
use App\Models\Trade;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake(['discord.com/*' => Http::response([], 200)]);
    config(['services.discord.token' => 'token', 'services.discord.channel_id' => '123']);
});

it('posts one header embed plus one embed per active persona', function () {
    Persona::factory()->count(8)->create(['is_active' => true]);

    PostWeeklyReportJob::dispatchSync();

    Http::assertSent(function ($request) {
        return count($request->data()['embeds']) === 9;
    });
});

it('excludes inactive personas from the report', function () {
    Persona::factory()->create(['name' => 'Active Bot', 'is_active' => true]);
    Persona::factory()->inactive()->create(['name' => 'Inactive Bot']);

    PostWeeklyReportJob::dispatchSync();

    Http::assertSent(function ($request) {
        $embeds = $request->data()['embeds'];
        $allTitles = collect($embeds)->pluck('title')->join(' ');

        return count($embeds) === 2
            && ! str_contains($allTitles, 'Inactive Bot');
    });
});

it('first embed is the leaderboard header with gold color', function () {
    Persona::factory()->create(['is_active' => true]);

    PostWeeklyReportJob::dispatchSync();

    Http::assertSent(function ($request) {
        $header = $request->data()['embeds'][0];

        return str_contains($header['title'], 'Weekly Trading Report')
            && str_contains($header['description'], 'LEADERBOARD')
            && $header['color'] === 16766720;
    });
});

it('ranks personas by total portfolio value descending in the header', function () {
    Persona::factory()->create(['name' => 'Rich Bot', 'cash_balance' => 12000, 'is_active' => true]);
    Persona::factory()->create(['name' => 'Poor Bot', 'cash_balance' => 8000, 'is_active' => true]);

    PostWeeklyReportJob::dispatchSync();

    Http::assertSent(function ($request) {
        $description = $request->data()['embeds'][0]['description'];
        $richPos = strpos($description, 'Rich Bot');
        $poorPos = strpos($description, 'Poor Bot');

        return $richPos !== false && $poorPos !== false && $richPos < $poorPos;
    });
});

it('includes best and worst callout in header when prior snapshots exist', function () {
    $winner = Persona::factory()->create(['name' => 'Winner Bot', 'cash_balance' => 11000, 'is_active' => true]);
    $loser = Persona::factory()->create(['name' => 'Loser Bot', 'cash_balance' => 9000, 'is_active' => true]);

    PersonaPortfolioSnapshot::factory()->create(['persona_id' => $winner->id, 'total_value' => 10000]);
    PersonaPortfolioSnapshot::factory()->create(['persona_id' => $loser->id, 'total_value' => 10000]);

    PostWeeklyReportJob::dispatchSync();

    Http::assertSent(function ($request) {
        $description = $request->data()['embeds'][0]['description'];

        return str_contains($description, 'Best this week')
            && str_contains($description, 'Worst this week');
    });
});

it('omits WoW callout from header on first run with no prior snapshots', function () {
    Persona::factory()->create(['cash_balance' => 10000, 'is_active' => true]);

    PostWeeklyReportJob::dispatchSync();

    Http::assertSent(function ($request) {
        $description = $request->data()['embeds'][0]['description'];

        return ! str_contains($description, 'Best this week')
            && ! str_contains($description, 'Worst this week');
    });
});

it('colors persona embed green when WoW change is positive', function () {
    $persona = Persona::factory()->create(['cash_balance' => 11000, 'is_active' => true]);
    PersonaPortfolioSnapshot::factory()->create(['persona_id' => $persona->id, 'total_value' => 10000]);

    PostWeeklyReportJob::dispatchSync();

    Http::assertSent(function ($request) {
        return $request->data()['embeds'][1]['color'] === 5746727;
    });
});

it('colors persona embed red when WoW change is negative', function () {
    $persona = Persona::factory()->create(['cash_balance' => 9000, 'is_active' => true]);
    PersonaPortfolioSnapshot::factory()->create(['persona_id' => $persona->id, 'total_value' => 10000]);

    PostWeeklyReportJob::dispatchSync();

    Http::assertSent(function ($request) {
        return $request->data()['embeds'][1]['color'] === 15548997;
    });
});

it('colors persona embed neutral blue when no prior snapshot exists', function () {
    Persona::factory()->create(['cash_balance' => 10000, 'is_active' => true]);

    PostWeeklyReportJob::dispatchSync();

    Http::assertSent(function ($request) {
        return $request->data()['embeds'][1]['color'] === 3447003;
    });
});

it('shows em dash for week change field when no prior snapshot exists', function () {
    Persona::factory()->create(['cash_balance' => 10000, 'is_active' => true]);

    PostWeeklyReportJob::dispatchSync();

    Http::assertSent(function ($request) {
        $fields = $request->data()['embeds'][1]['fields'];
        $wowField = collect($fields)->firstWhere('name', 'Week Change');

        return $wowField['value'] === '—';
    });
});

it('persona embed has three inline summary fields', function () {
    Persona::factory()->create(['cash_balance' => 10000, 'is_active' => true]);

    PostWeeklyReportJob::dispatchSync();

    Http::assertSent(function ($request) {
        $fields = collect($request->data()['embeds'][1]['fields']);
        $inlineFields = $fields->where('inline', true);

        return $inlineFields->count() === 3
            && $inlineFields->pluck('name')->contains('Total Value')
            && $inlineFields->pluck('name')->contains('Week Change')
            && $inlineFields->pluck('name')->contains('Cash');
    });
});

it('shows open positions with price and unrealised pnl', function () {
    $persona = Persona::factory()->create(['cash_balance' => 5000, 'is_active' => true]);
    Position::factory()->for($persona)->create([
        'ticker' => 'AAPL',
        'shares' => 5,
        'average_cost' => 140.00,
    ]);
    PriceSnapshot::factory()->forTicker('AAPL')->create(['price' => 150.00, 'fetched_at' => now()]);

    PostWeeklyReportJob::dispatchSync();

    Http::assertSent(function ($request) {
        $fields = $request->data()['embeds'][1]['fields'];
        $posField = collect($fields)->firstWhere('name', 'Open Positions');

        return str_contains($posField['value'], 'AAPL')
            && str_contains($posField['value'], '150.00')
            && str_contains($posField['value'], '750.00');
    });
});

it('shows no open positions when persona has none', function () {
    Persona::factory()->create(['cash_balance' => 10000, 'is_active' => true]);

    PostWeeklyReportJob::dispatchSync();

    Http::assertSent(function ($request) {
        $fields = $request->data()['embeds'][1]['fields'];
        $posField = collect($fields)->firstWhere('name', 'Open Positions');

        return $posField['value'] === 'No open positions';
    });
});

it('shows no price data label for positions with no snapshot', function () {
    $persona = Persona::factory()->create(['cash_balance' => 5000, 'is_active' => true]);
    Position::factory()->for($persona)->create([
        'ticker' => 'AAPL',
        'shares' => 10,
        'average_cost' => 150.00,
    ]);

    PostWeeklyReportJob::dispatchSync();

    Http::assertSent(function ($request) {
        $fields = $request->data()['embeds'][1]['fields'];
        $posField = collect($fields)->firstWhere('name', 'Open Positions');

        return str_contains($posField['value'], 'no price data');
    });
});

it('uses average_cost as fallback for total value when position has no price snapshot', function () {
    $persona = Persona::factory()->create(['cash_balance' => 5000, 'is_active' => true]);
    Position::factory()->for($persona)->create([
        'ticker' => 'AAPL',
        'shares' => 10,
        'average_cost' => 150.00,
    ]);

    PostWeeklyReportJob::dispatchSync();

    $snapshot = PersonaPortfolioSnapshot::first();
    // 5000 (cash) + 10 * 150.00 (fallback) = 6500
    expect((float) $snapshot->total_value)->toBe(6500.0);
});

it('shows weekly trade count in persona embed', function () {
    $persona = Persona::factory()->create(['cash_balance' => 10000, 'is_active' => true]);
    Trade::factory()->for($persona)->buy()->create(['executed_at' => now()->subDays(3)]);
    Trade::factory()->for($persona)->buy()->create(['executed_at' => now()->subDays(2)]);
    Trade::factory()->for($persona)->sell()->create(['executed_at' => now()->subDays(1)]);

    PostWeeklyReportJob::dispatchSync();

    Http::assertSent(function ($request) {
        $fields = $request->data()['embeds'][1]['fields'];
        $tradesField = collect($fields)->firstWhere('name', 'Weekly Trades');

        return str_contains($tradesField['value'], '3  (')
            && str_contains($tradesField['value'], '2 buys')
            && str_contains($tradesField['value'], '1 sells');
    });
});

it('shows no trades this week when persona has none in the period', function () {
    Persona::factory()->create(['cash_balance' => 10000, 'is_active' => true]);

    PostWeeklyReportJob::dispatchSync();

    Http::assertSent(function ($request) {
        $fields = $request->data()['embeds'][1]['fields'];
        $tradesField = collect($fields)->firstWhere('name', 'Weekly Trades');

        return $tradesField['value'] === 'No trades this week';
    });
});

it('excludes trades outside the reporting period from the trade count', function () {
    $persona = Persona::factory()->create(['cash_balance' => 10000, 'is_active' => true]);
    Trade::factory()->for($persona)->buy()->create(['executed_at' => now()->subDays(8)]);

    PostWeeklyReportJob::dispatchSync();

    Http::assertSent(function ($request) {
        $fields = $request->data()['embeds'][1]['fields'];
        $tradesField = collect($fields)->firstWhere('name', 'Weekly Trades');

        return $tradesField['value'] === 'No trades this week';
    });
});

it('saves one PersonaPortfolioSnapshot per persona after successful post', function () {
    Persona::factory()->count(3)->create(['is_active' => true]);

    PostWeeklyReportJob::dispatchSync();

    expect(PersonaPortfolioSnapshot::count())->toBe(3);
});

it('snapshot total_value includes cash plus open position market values', function () {
    $persona = Persona::factory()->create(['cash_balance' => 5000, 'is_active' => true]);
    Position::factory()->for($persona)->create(['ticker' => 'NVDA', 'shares' => 2, 'average_cost' => 800.00]);
    PriceSnapshot::factory()->forTicker('NVDA')->create(['price' => 900.00, 'fetched_at' => now()]);

    PostWeeklyReportJob::dispatchSync();

    $snapshot = PersonaPortfolioSnapshot::first();
    // 5000 (cash) + 2 * 900 (market) = 6800
    expect((float) $snapshot->total_value)->toBe(6800.0);
});

it('does not save snapshots if Discord post fails', function () {
    Http::swap(new Factory);
    Http::fake(['discord.com/*' => Http::response(['message' => 'Error'], 500)]);
    Persona::factory()->create(['is_active' => true]);

    expect(fn () => PostWeeklyReportJob::dispatchSync())->toThrow(Exception::class);
    expect(PersonaPortfolioSnapshot::count())->toBe(0);
});

it('logs a DiscordReport after successful post', function () {
    Persona::factory()->create(['is_active' => true]);

    PostWeeklyReportJob::dispatchSync();

    expect(DiscordReport::count())->toBe(1);
});

it('does not log a DiscordReport if the Discord API call fails', function () {
    Http::swap(new Factory);
    Http::fake(['discord.com/*' => Http::response(['message' => 'Error'], 500)]);
    Persona::factory()->create(['is_active' => true]);

    expect(fn () => PostWeeklyReportJob::dispatchSync())->toThrow(Exception::class);
    expect(DiscordReport::count())->toBe(0);
});
