# Weekly Report Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the flat single-embed weekly Discord report with a ranked competitive leaderboard (header embed) + one rich card per persona, backed by a new `persona_portfolio_snapshots` table for week-over-week comparisons.

**Architecture:** A new `PersonaPortfolioSnapshot` model records each persona's total portfolio value (cash + positions at current price) after every successful report post. `PostWeeklyReportJob` is fully rewritten to rank personas by total value, build a gold-bordered leaderboard header embed and 8 color-coded persona card embeds, then bulk-insert snapshots and log the `DiscordReport`. `DiscordService` is unchanged.

**Tech Stack:** Laravel 13, PHP 8.4, Pest 4, Discord Embed API (v10)

---

## File Map

| Action | File |
|---|---|
| Create | `database/migrations/2026_05_11_XXXXXX_create_persona_portfolio_snapshots_table.php` |
| Create | `app/Models/PersonaPortfolioSnapshot.php` |
| Create | `database/factories/PersonaPortfolioSnapshotFactory.php` |
| Modify | `app/Models/Persona.php` — add `portfolioSnapshots()` HasMany |
| Rewrite | `app/Jobs/PostWeeklyReportJob.php` |
| Rewrite | `tests/Feature/PostWeeklyReportJobTest.php` |

---

## Task 1: Scaffold PersonaPortfolioSnapshot

**Files:**
- Create: `app/Models/PersonaPortfolioSnapshot.php`
- Create: `database/factories/PersonaPortfolioSnapshotFactory.php`
- Create: `database/migrations/..._create_persona_portfolio_snapshots_table.php`

- [ ] **Step 1: Generate the model with migration and factory**

```bash
php artisan make:model PersonaPortfolioSnapshot --migration --factory --no-interaction
```

- [ ] **Step 2: Fill in the migration**

Open the generated migration file at `database/migrations/..._create_persona_portfolio_snapshots_table.php` and replace the `up()` method body:

```php
public function up(): void
{
    Schema::create('persona_portfolio_snapshots', function (Blueprint $table) {
        $table->id();
        $table->foreignId('persona_id')->constrained()->cascadeOnDelete();
        $table->decimal('total_value', 15, 2);
        $table->decimal('cash_balance', 15, 2);
        $table->timestamp('snapshotted_at');
        $table->timestamps();
    });
}
```

- [ ] **Step 3: Fill in the model** (`app/Models/PersonaPortfolioSnapshot.php`)

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonaPortfolioSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'persona_id',
        'total_value',
        'cash_balance',
        'snapshotted_at',
    ];

    protected $casts = [
        'total_value' => 'decimal:2',
        'cash_balance' => 'decimal:2',
        'snapshotted_at' => 'datetime',
    ];

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }
}
```

- [ ] **Step 4: Fill in the factory** (`database/factories/PersonaPortfolioSnapshotFactory.php`)

```php
<?php

namespace Database\Factories;

use App\Models\Persona;
use Illuminate\Database\Eloquent\Factories\Factory;

class PersonaPortfolioSnapshotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'persona_id' => Persona::factory(),
            'total_value' => $this->faker->randomFloat(2, 8000, 12000),
            'cash_balance' => $this->faker->randomFloat(2, 2000, 8000),
            'snapshotted_at' => now()->subWeek(),
        ];
    }
}
```

- [ ] **Step 5: Add `portfolioSnapshots()` to the Persona model** (`app/Models/Persona.php`)

Add the import and method. The existing imports are at the top; add `PersonaPortfolioSnapshot` to the use block and add this method after `candidateTickers()`:

```php
use App\Models\PersonaPortfolioSnapshot; // add to imports

public function portfolioSnapshots(): HasMany
{
    return $this->hasMany(PersonaPortfolioSnapshot::class);
}
```

- [ ] **Step 6: Run the migration**

```bash
php artisan migrate --no-interaction
```

Expected: `Running migrations. ...create_persona_portfolio_snapshots_table ... DONE`

- [ ] **Step 7: Run the existing test suite to confirm nothing is broken**

```bash
php artisan test --compact
```

Expected: all tests pass (currently 70).

- [ ] **Step 8: Commit**

```bash
git add app/Models/PersonaPortfolioSnapshot.php \
        app/Models/Persona.php \
        database/factories/PersonaPortfolioSnapshotFactory.php \
        database/migrations/
git commit -m "feat: add PersonaPortfolioSnapshot model, migration, and factory"
```

---

## Task 2: Write Failing Tests

**Files:**
- Rewrite: `tests/Feature/PostWeeklyReportJobTest.php`

- [ ] **Step 1: Replace the entire test file**

```php
<?php

use App\Jobs\PostWeeklyReportJob;
use App\Models\DiscordReport;
use App\Models\Persona;
use App\Models\PersonaPortfolioSnapshot;
use App\Models\Position;
use App\Models\PriceSnapshot;
use App\Models\Trade;
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

        return strpos($description, 'Rich Bot') < strpos($description, 'Poor Bot');
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

        return str_contains($tradesField['value'], '3')
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
    Http::fake(['discord.com/*' => Http::response(['message' => 'Error'], 500)]);
    Persona::factory()->create(['is_active' => true]);

    expect(fn () => PostWeeklyReportJob::dispatchSync())->toThrow(\Exception::class);
    expect(PersonaPortfolioSnapshot::count())->toBe(0);
});

it('logs a DiscordReport after successful post', function () {
    Persona::factory()->create(['is_active' => true]);

    PostWeeklyReportJob::dispatchSync();

    expect(DiscordReport::count())->toBe(1);
});

it('does not log a DiscordReport if the Discord API call fails', function () {
    Http::fake(['discord.com/*' => Http::response(['message' => 'Error'], 500)]);
    Persona::factory()->create(['is_active' => true]);

    expect(fn () => PostWeeklyReportJob::dispatchSync())->toThrow(\Exception::class);
    expect(DiscordReport::count())->toBe(0);
});
```

- [ ] **Step 2: Run the new tests to confirm they all fail**

```bash
php artisan test --compact --filter=PostWeeklyReportJobTest
```

Expected: multiple failures — the old job doesn't produce the new embed structure.

- [ ] **Step 3: Commit the failing tests**

```bash
git add tests/Feature/PostWeeklyReportJobTest.php
git commit -m "test: replace PostWeeklyReportJob tests with new competitive report spec"
```

---

## Task 3: Implement the Rewritten PostWeeklyReportJob

**Files:**
- Rewrite: `app/Jobs/PostWeeklyReportJob.php`

- [ ] **Step 1: Replace the entire job file** (`app/Jobs/PostWeeklyReportJob.php`)

```php
<?php

namespace App\Jobs;

use App\Enums\StrategyType;
use App\Enums\TradeAction;
use App\Models\DiscordReport;
use App\Models\Persona;
use App\Models\PersonaPortfolioSnapshot;
use App\Models\PriceSnapshot;
use App\Services\DiscordService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

class PostWeeklyReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    private const COLOR_GREEN = 5746727;

    private const COLOR_RED = 15548997;

    private const COLOR_BLUE = 3447003;

    private const COLOR_GOLD = 16766720;

    private const RANK_MEDALS = ['🥇', '🥈', '🥉', '4️⃣', '5️⃣', '6️⃣', '7️⃣', '8️⃣'];

    public function handle(DiscordService $discord): void
    {
        $periodEnd = now();
        $periodStart = $periodEnd->copy()->subWeek();

        $personas = Persona::where('is_active', true)
            ->with([
                'openPositions',
                'trades' => fn ($q) => $q->whereBetween('executed_at', [$periodStart, $periodEnd]),
            ])
            ->get();

        $tickers = $personas->flatMap(fn ($p) => $p->openPositions->pluck('ticker'))->unique()->values();

        $latestSnapshots = $tickers->isEmpty()
            ? collect()
            : PriceSnapshot::whereIn('ticker', $tickers)
                ->orderByDesc('fetched_at')
                ->get()
                ->unique('ticker')
                ->keyBy('ticker');

        $totalValues = $personas->mapWithKeys(fn (Persona $persona) => [
            $persona->id => $this->computeTotalValue($persona, $latestSnapshots),
        ]);

        $previousSnapshots = PersonaPortfolioSnapshot::query()
            ->whereIn('persona_id', $personas->pluck('id'))
            ->latest('snapshotted_at')
            ->get()
            ->unique('persona_id')
            ->keyBy('persona_id');

        $ranked = $personas->sortByDesc(fn (Persona $p) => $totalValues[$p->id])->values();

        $embeds = [$this->buildHeaderEmbed($ranked, $totalValues, $previousSnapshots, $periodStart, $periodEnd)];

        foreach ($ranked as $rank => $persona) {
            $embeds[] = $this->buildPersonaEmbed(
                $persona,
                $rank + 1,
                $totalValues[$persona->id],
                $previousSnapshots->get($persona->id),
                $latestSnapshots,
            );
        }

        $discord->postMessage($embeds);

        $now = now();

        PersonaPortfolioSnapshot::insert(
            $personas->map(fn (Persona $persona) => [
                'persona_id' => $persona->id,
                'total_value' => $totalValues[$persona->id],
                'cash_balance' => $persona->cash_balance,
                'snapshotted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])->toArray()
        );

        DiscordReport::create([
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'payload' => ['embeds' => $embeds],
            'posted_at' => $now,
        ]);
    }

    private function computeTotalValue(Persona $persona, Collection $latestSnapshots): float
    {
        $positionsValue = $persona->openPositions->sum(function ($position) use ($latestSnapshots) {
            $snapshot = $latestSnapshots->get($position->ticker);
            $price = $snapshot ? (float) $snapshot->price : (float) $position->average_cost;

            return (float) $position->shares * $price;
        });

        return (float) $persona->cash_balance + $positionsValue;
    }

    /** @param Collection<int, Persona> $ranked */
    private function buildHeaderEmbed(
        Collection $ranked,
        Collection $totalValues,
        Collection $previousSnapshots,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): array {
        $hasHistory = $previousSnapshots->isNotEmpty();

        $rows = $ranked->map(function (Persona $persona, int $index) use ($totalValues, $previousSnapshots, $hasHistory) {
            $medal = self::RANK_MEDALS[$index] ?? ($index + 1).'️⃣';
            $name = str_pad($persona->name, 24);
            $total = $totalValues[$persona->id];
            $valueStr = '$'.number_format($total, 0);

            if ($hasHistory && $previousSnapshots->has($persona->id)) {
                $prev = (float) $previousSnapshots[$persona->id]->total_value;
                $delta = $total - $prev;
                $pct = $prev > 0 ? ($delta / $prev) * 100 : 0.0;
                $sign = $delta >= 0 ? '+' : '-';
                $wowStr = "  {$sign}\$".number_format(abs($delta), 0)."  ({$sign}".number_format(abs($pct), 1).'%)';

                return "{$medal} {$name} {$valueStr}{$wowStr}";
            }

            return "{$medal} {$name} {$valueStr}";
        })->join("\n");

        $description = 'Period: '.$periodStart->format('M j').' – '.$periodEnd->format('M j, Y')."\n\n**🏆 LEADERBOARD**\n".$rows;

        if ($hasHistory) {
            $withWoW = $ranked
                ->filter(fn (Persona $p) => $previousSnapshots->has($p->id))
                ->map(function (Persona $p) use ($totalValues, $previousSnapshots) {
                    $prev = (float) $previousSnapshots[$p->id]->total_value;
                    $pct = $prev > 0 ? (($totalValues[$p->id] - $prev) / $prev) * 100 : 0.0;

                    return ['name' => $p->name, 'pct' => $pct];
                });

            if ($withWoW->isNotEmpty()) {
                $best = $withWoW->sortByDesc('pct')->first();
                $worst = $withWoW->sortBy('pct')->first();
                $bestSign = $best['pct'] >= 0 ? '+' : '';
                $worstSign = $worst['pct'] >= 0 ? '+' : '';
                $description .= "\n\n📈 **Best this week:** {$best['name']}  ({$bestSign}".number_format($best['pct'], 1)."%)";
                $description .= "\n📉 **Worst this week:** {$worst['name']}  ({$worstSign}".number_format($worst['pct'], 1)."%)";
            }
        }

        return [
            'title' => '📊 Weekly Trading Report',
            'description' => $description,
            'color' => self::COLOR_GOLD,
            'timestamp' => $periodEnd->toIso8601String(),
        ];
    }

    private function buildPersonaEmbed(
        Persona $persona,
        int $rank,
        float $totalValue,
        ?PersonaPortfolioSnapshot $previousSnapshot,
        Collection $latestSnapshots,
    ): array {
        $medal = self::RANK_MEDALS[$rank - 1] ?? $rank.'️⃣';

        $wowValue = '—';
        $color = self::COLOR_BLUE;

        if ($previousSnapshot !== null) {
            $prev = (float) $previousSnapshot->total_value;
            $delta = $totalValue - $prev;
            $pct = $prev > 0 ? ($delta / $prev) * 100 : 0.0;
            $sign = $delta >= 0 ? '+' : '-';
            $wowValue = "{$sign}\$".number_format(abs($delta), 2).' ('.$sign.number_format(abs($pct), 1).'%)';
            $color = match (true) {
                $delta > 0 => self::COLOR_GREEN,
                $delta < 0 => self::COLOR_RED,
                default    => self::COLOR_BLUE,
            };
        }

        $strategyLabel = match ($persona->strategy_type) {
            StrategyType::Momentum => '📈 Momentum',
            StrategyType::MeanReversion => '🔄 Mean Reversion',
        };

        $positionsValue = $persona->openPositions->isEmpty()
            ? 'No open positions'
            : $persona->openPositions->map(function ($position) use ($latestSnapshots) {
                $snapshot = $latestSnapshots->get($position->ticker);

                if ($snapshot === null) {
                    return "• {$position->ticker}  {$position->shares} shares (no price data)";
                }

                $shares = rtrim(rtrim(number_format((float) $position->shares, 4), '0'), '.');
                $price = number_format((float) $snapshot->price, 2);
                $value = number_format((float) $position->shares * (float) $snapshot->price, 2);
                $pnl = ((float) $snapshot->price - (float) $position->average_cost) * (float) $position->shares;
                $pnlSign = $pnl >= 0 ? '+' : '';
                $dot = $pnl >= 0 ? '🟢' : '🔴';

                return "• {$position->ticker}  {$shares} × \${$price} = \${$value}    ({$pnlSign}".number_format($pnl, 2).") {$dot}";
            })->join("\n");

        $tradesValue = $persona->trades->isEmpty()
            ? 'No trades this week'
            : $persona->trades->count().'  ('
                .$persona->trades->filter(fn ($t) => $t->action === TradeAction::Buy)->count().' buys · '
                .$persona->trades->filter(fn ($t) => $t->action === TradeAction::Sell)->count().' sells)';

        return [
            'title' => "{$medal} {$persona->name}",
            'description' => $strategyLabel,
            'color' => $color,
            'fields' => [
                ['name' => 'Total Value', 'value' => '$'.number_format($totalValue, 2), 'inline' => true],
                ['name' => 'Week Change', 'value' => $wowValue, 'inline' => true],
                ['name' => 'Cash', 'value' => '$'.number_format((float) $persona->cash_balance, 2), 'inline' => true],
                ['name' => 'Open Positions', 'value' => $positionsValue, 'inline' => false],
                ['name' => 'Weekly Trades', 'value' => $tradesValue, 'inline' => false],
            ],
        ];
    }
}
```

- [ ] **Step 2: Run the new tests**

```bash
php artisan test --compact --filter=PostWeeklyReportJobTest
```

Expected: all tests pass.

- [ ] **Step 3: Run the full test suite**

```bash
php artisan test --compact
```

Expected: all tests pass.

- [ ] **Step 4: Format with Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 5: Run the full test suite again after formatting**

```bash
php artisan test --compact
```

Expected: all tests still pass.

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/PostWeeklyReportJob.php
git commit -m "feat: rewrite PostWeeklyReportJob as competitive leaderboard with per-persona cards"
```

---

## Task 4: Commit the Plan

- [ ] **Step 1: Commit this plan document**

```bash
git add docs/superpowers/plans/2026-05-11-weekly-report-redesign.md
git commit -m "docs: add weekly report redesign implementation plan"
```
