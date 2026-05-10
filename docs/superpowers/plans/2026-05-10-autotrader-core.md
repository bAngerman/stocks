# Autotrader Core — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a paper trading bot that runs strategy personas on an intraday queue-based schedule, executes mock trades against a local database, optionally consults Claude AI for borderline signals, and posts weekly P&L summaries to Discord via bot token.

**Architecture:** Laravel Scheduler dispatches one `EvaluatePersonaJob` per active persona every 15 minutes during NYSE market hours. Each job fetches prices via Yahoo Finance, runs a strategy class, and either dispatches `ExecuteTradeJob` directly (high-confidence signals) or routes through `AIEvaluationJob` first (borderline signals). `PostWeeklyReportJob` fires every Friday at noon MT and posts a Discord embed via `DiscordService`. All positions are mocked in the local database — no external brokerage.

**Tech Stack:** Laravel 13, PHP 8.4, `scheb/yahoo-finance-api`, Anthropic API (Laravel HTTP client), Discord REST API (Laravel HTTP client), Pest 4, SQLite (tests)

---

## File Map

**Enums (new):**
- `app/Enums/StrategyType.php`
- `app/Enums/TradeAction.php`

**Value Objects (new):**
- `app/Trading/TradeSignal.php`
- `app/Trading/MarketQuote.php`

**Strategy Contracts & Implementations (new):**
- `app/Trading/StrategyInterface.php`
- `app/Trading/Strategies/MomentumStrategy.php`

**Models (new):**
- `app/Models/Persona.php`
- `app/Models/Position.php`
- `app/Models/Trade.php`
- `app/Models/PriceSnapshot.php`
- `app/Models/DiscordReport.php`

**Migrations (new, via artisan):**
- `database/migrations/*_create_personas_table.php`
- `database/migrations/*_create_positions_table.php`
- `database/migrations/*_create_trades_table.php`
- `database/migrations/*_create_price_snapshots_table.php`
- `database/migrations/*_create_discord_reports_table.php`

**Factories (new, via artisan):**
- `database/factories/PersonaFactory.php`
- `database/factories/PositionFactory.php`
- `database/factories/TradeFactory.php`
- `database/factories/PriceSnapshotFactory.php`
- `database/factories/DiscordReportFactory.php`

**Services (new):**
- `app/Services/MarketDataService.php`
- `app/Services/AIEvaluator.php`
- `app/Services/DiscordService.php`

**Jobs (new):**
- `app/Jobs/EvaluatePersonaJob.php`
- `app/Jobs/AIEvaluationJob.php`
- `app/Jobs/ExecuteTradeJob.php`
- `app/Jobs/PostWeeklyReportJob.php`

**Modified:**
- `app/Providers/AppServiceProvider.php` — registers `MarketDataService` and `DiscordService` singletons
- `config/services.php` — adds `anthropic` and `discord` config blocks
- `routes/console.php` — wires up scheduler (15-min polling + Friday report)
- `.env.example` — adds `DISCORD_BOT_TOKEN`, `DISCORD_CHANNEL_ID`, `ANTHROPIC_API_KEY`
- `tests/Pest.php` — enables `RefreshDatabase` globally for Feature tests

**Tests (new):**
- `tests/Feature/MomentumStrategyTest.php`
- `tests/Feature/ExecuteTradeJobTest.php`
- `tests/Feature/EvaluatePersonaJobTest.php`
- `tests/Feature/AIEvaluationJobTest.php`
- `tests/Feature/MarketDataServiceTest.php`
- `tests/Feature/DiscordServiceTest.php`
- `tests/Feature/PostWeeklyReportJobTest.php`

---

### Task 1: Install dependencies and configure services

**Files:**
- Modify: `composer.json` (via composer require)
- Modify: `config/services.php`
- Modify: `.env.example`

- [ ] **Step 1: Install Yahoo Finance API package**

```bash
composer require scheb/yahoo-finance-api --no-interaction
```

Expected: Package installs successfully, `composer.lock` updated.

- [ ] **Step 2: Add service config blocks to `config/services.php`**

Open `config/services.php` and add before the closing `];`:

```php
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
        'version' => '2023-06-01',
    ],

    'discord' => [
        'token' => env('DISCORD_BOT_TOKEN'),
        'channel_id' => env('DISCORD_CHANNEL_ID'),
    ],
```

- [ ] **Step 3: Update `.env.example`**

Add these lines to `.env.example`:

```
ANTHROPIC_API_KEY=
ANTHROPIC_MODEL=claude-sonnet-4-6
DISCORD_BOT_TOKEN=
DISCORD_CHANNEL_ID=
```

- [ ] **Step 4: Verify config loads**

```bash
php artisan config:show services.anthropic
php artisan config:show services.discord
```

Expected: Both blocks output with their keys.

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock config/services.php .env.example
git commit -m "feat: install yahoo-finance-api and add Anthropic/Discord service config"
```

---

### Task 2: Enums — StrategyType and TradeAction

**Files:**
- Create: `app/Enums/StrategyType.php`
- Create: `app/Enums/TradeAction.php`

- [ ] **Step 1: Scaffold and write StrategyType enum**

```bash
php artisan make:enum Enums/StrategyType --no-interaction
```

Replace the generated content of `app/Enums/StrategyType.php`:

```php
<?php

namespace App\Enums;

use App\Trading\StrategyInterface;

enum StrategyType: string
{
    case Momentum = 'momentum';
    case MeanReversion = 'mean_reversion';

    public function strategyClass(): string
    {
        return match ($this) {
            self::Momentum => \App\Trading\Strategies\MomentumStrategy::class,
            self::MeanReversion => \App\Trading\Strategies\MeanReversionStrategy::class,
        };
    }

    public function make(): StrategyInterface
    {
        return app($this->strategyClass());
    }
}
```

- [ ] **Step 2: Scaffold and write TradeAction enum**

```bash
php artisan make:enum Enums/TradeAction --no-interaction
```

Replace `app/Enums/TradeAction.php`:

```php
<?php

namespace App\Enums;

enum TradeAction: string
{
    case Buy = 'buy';
    case Sell = 'sell';
}
```

- [ ] **Step 3: Format and commit**

```bash
vendor/bin/pint app/Enums/ --format agent
git add app/Enums/
git commit -m "feat: add StrategyType and TradeAction enums"
```

---

### Task 3: Value objects — TradeSignal and MarketQuote

**Files:**
- Create: `app/Trading/TradeSignal.php`
- Create: `app/Trading/MarketQuote.php`

- [ ] **Step 1: Create the Trading directory and TradeSignal**

```bash
php artisan make:class Trading/TradeSignal --no-interaction
```

Replace `app/Trading/TradeSignal.php`:

```php
<?php

namespace App\Trading;

use App\Enums\TradeAction;

readonly class TradeSignal
{
    public function __construct(
        public string $ticker,
        public TradeAction $action,
        public float $shares,
        public string $reason,
        public float $confidence,
        public bool $shouldConsultAI,
    ) {}
}
```

- [ ] **Step 2: Create MarketQuote**

```bash
php artisan make:class Trading/MarketQuote --no-interaction
```

Replace `app/Trading/MarketQuote.php`:

```php
<?php

namespace App\Trading;

use Carbon\Carbon;

readonly class MarketQuote
{
    public function __construct(
        public string $ticker,
        public float $price,
        public float $changePercent,
        public Carbon $fetchedAt,
    ) {}
}
```

- [ ] **Step 3: Format and commit**

```bash
vendor/bin/pint app/Trading/ --format agent
git add app/Trading/
git commit -m "feat: add TradeSignal and MarketQuote value objects"
```

---

### Task 4: Domain models, migrations, and factories

**Files:**
- Create: 5 models, 5 migrations, 5 factories (via artisan)
- Modify: `tests/Pest.php`

- [ ] **Step 1: Enable RefreshDatabase globally for Feature tests**

In `tests/Pest.php`, uncomment the `->use(RefreshDatabase::class)` line:

```php
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
```

- [ ] **Step 2: Scaffold all models with migrations and factories**

```bash
php artisan make:model Persona -mf --no-interaction
php artisan make:model Position -mf --no-interaction
php artisan make:model Trade -mf --no-interaction
php artisan make:model PriceSnapshot -mf --no-interaction
php artisan make:model DiscordReport -mf --no-interaction
```

- [ ] **Step 3: Write the personas migration**

In `database/migrations/*_create_personas_table.php`, replace the `up()` method body:

```php
Schema::create('personas', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->text('description')->nullable();
    $table->decimal('cash_balance', 15, 2)->default(0);
    $table->string('strategy_type');
    $table->json('strategy_parameters');
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

- [ ] **Step 4: Write the positions migration**

```php
Schema::create('positions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('persona_id')->constrained()->cascadeOnDelete();
    $table->string('ticker');
    $table->decimal('shares', 15, 4)->default(0);
    $table->decimal('average_cost', 10, 4)->default(0);
    $table->timestamp('opened_at');
    $table->timestamps();

    $table->unique(['persona_id', 'ticker']);
});
```

- [ ] **Step 5: Write the trades migration**

```php
Schema::create('trades', function (Blueprint $table) {
    $table->id();
    $table->foreignId('persona_id')->constrained()->cascadeOnDelete();
    $table->string('ticker');
    $table->string('action');
    $table->decimal('shares', 15, 4);
    $table->decimal('price_per_share', 10, 4);
    $table->decimal('total_value', 15, 2);
    $table->text('signal_reason');
    $table->text('ai_rationale')->nullable();
    $table->timestamp('executed_at');
    $table->timestamps();
});
```

- [ ] **Step 6: Write the price_snapshots migration**

```php
Schema::create('price_snapshots', function (Blueprint $table) {
    $table->id();
    $table->string('ticker');
    $table->decimal('price', 10, 4);
    $table->decimal('change_percent', 8, 4);
    $table->timestamp('fetched_at');
    $table->timestamps();

    $table->index(['ticker', 'fetched_at']);
});
```

- [ ] **Step 7: Write the discord_reports migration**

```php
Schema::create('discord_reports', function (Blueprint $table) {
    $table->id();
    $table->timestamp('period_start');
    $table->timestamp('period_end');
    $table->json('payload');
    $table->timestamp('posted_at');
    $table->timestamps();
});
```

- [ ] **Step 8: Write the Persona model**

Replace `app/Models/Persona.php`:

```php
<?php

namespace App\Models;

use App\Enums\StrategyType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Persona extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'cash_balance',
        'strategy_type',
        'strategy_parameters',
        'is_active',
    ];

    protected $casts = [
        'strategy_type' => StrategyType::class,
        'strategy_parameters' => 'array',
        'cash_balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }

    public function openPositions(): HasMany
    {
        return $this->positions()->where('shares', '>', 0);
    }
}
```

- [ ] **Step 9: Write the Position model**

Replace `app/Models/Position.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Position extends Model
{
    use HasFactory;

    protected $fillable = [
        'persona_id',
        'ticker',
        'shares',
        'average_cost',
        'opened_at',
    ];

    protected $casts = [
        'shares' => 'decimal:4',
        'average_cost' => 'decimal:4',
        'opened_at' => 'datetime',
    ];

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }
}
```

- [ ] **Step 10: Write the Trade model**

Replace `app/Models/Trade.php`:

```php
<?php

namespace App\Models;

use App\Enums\TradeAction;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Trade extends Model
{
    use HasFactory;

    protected $fillable = [
        'persona_id',
        'ticker',
        'action',
        'shares',
        'price_per_share',
        'total_value',
        'signal_reason',
        'ai_rationale',
        'executed_at',
    ];

    protected $casts = [
        'action' => TradeAction::class,
        'shares' => 'decimal:4',
        'price_per_share' => 'decimal:4',
        'total_value' => 'decimal:2',
        'executed_at' => 'datetime',
    ];

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }
}
```

- [ ] **Step 11: Write the PriceSnapshot model**

Replace `app/Models/PriceSnapshot.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PriceSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticker',
        'price',
        'change_percent',
        'fetched_at',
    ];

    protected $casts = [
        'price' => 'decimal:4',
        'change_percent' => 'decimal:4',
        'fetched_at' => 'datetime',
    ];
}
```

- [ ] **Step 12: Write the DiscordReport model**

Replace `app/Models/DiscordReport.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DiscordReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'period_start',
        'period_end',
        'payload',
        'posted_at',
    ];

    protected $casts = [
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'payload' => 'array',
        'posted_at' => 'datetime',
    ];
}
```

- [ ] **Step 13: Write the PersonaFactory**

Replace `database/factories/PersonaFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\StrategyType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Persona>
 */
class PersonaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true),
            'description' => $this->faker->sentence(),
            'cash_balance' => 10000.00,
            'strategy_type' => StrategyType::Momentum,
            'strategy_parameters' => [
                'tickers' => ['AAPL', 'MSFT'],
                'buy_threshold' => 1.5,
                'sell_threshold' => 2.0,
                'ai_confidence_min' => 0.4,
                'ai_confidence_max' => 0.7,
                'shares_per_trade' => 1,
            ],
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function withTickers(array $tickers): static
    {
        return $this->state(function (array $attributes) use ($tickers) {
            $params = $attributes['strategy_parameters'];
            $params['tickers'] = $tickers;

            return ['strategy_parameters' => $params];
        });
    }
}
```

- [ ] **Step 14: Write the PositionFactory**

Replace `database/factories/PositionFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Persona;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Position>
 */
class PositionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'persona_id' => Persona::factory(),
            'ticker' => $this->faker->randomElement(['AAPL', 'MSFT', 'GOOGL', 'SPY', 'QQQ']),
            'shares' => $this->faker->randomFloat(4, 1, 100),
            'average_cost' => $this->faker->randomFloat(4, 50, 500),
            'opened_at' => now()->subDays($this->faker->numberBetween(1, 30)),
        ];
    }

    public function closed(): static
    {
        return $this->state(['shares' => 0]);
    }
}
```

- [ ] **Step 15: Write the TradeFactory**

Replace `database/factories/TradeFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\TradeAction;
use App\Models\Persona;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Trade>
 */
class TradeFactory extends Factory
{
    public function definition(): array
    {
        $shares = $this->faker->randomFloat(4, 1, 10);
        $price = $this->faker->randomFloat(4, 50, 500);

        return [
            'persona_id' => Persona::factory(),
            'ticker' => $this->faker->randomElement(['AAPL', 'MSFT', 'GOOGL', 'SPY']),
            'action' => $this->faker->randomElement(TradeAction::cases()),
            'shares' => $shares,
            'price_per_share' => $price,
            'total_value' => round($shares * $price, 2),
            'signal_reason' => $this->faker->sentence(),
            'ai_rationale' => null,
            'executed_at' => now(),
        ];
    }

    public function buy(): static
    {
        return $this->state(['action' => TradeAction::Buy]);
    }

    public function sell(): static
    {
        return $this->state(['action' => TradeAction::Sell]);
    }

    public function aiAssisted(): static
    {
        return $this->state(['ai_rationale' => fake()->sentence()]);
    }
}
```

- [ ] **Step 16: Write the PriceSnapshotFactory**

Replace `database/factories/PriceSnapshotFactory.php`:

```php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PriceSnapshot>
 */
class PriceSnapshotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ticker' => $this->faker->randomElement(['AAPL', 'MSFT', 'GOOGL', 'SPY', 'QQQ']),
            'price' => $this->faker->randomFloat(4, 50, 500),
            'change_percent' => $this->faker->randomFloat(4, -5.0, 5.0),
            'fetched_at' => now(),
        ];
    }

    public function stale(): static
    {
        return $this->state(['fetched_at' => now()->subHour()]);
    }

    public function forTicker(string $ticker): static
    {
        return $this->state(['ticker' => $ticker]);
    }
}
```

- [ ] **Step 17: Write the DiscordReportFactory**

Replace `database/factories/DiscordReportFactory.php`:

```php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DiscordReport>
 */
class DiscordReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'period_start' => now()->subWeek(),
            'period_end' => now(),
            'payload' => ['embeds' => []],
            'posted_at' => now(),
        ];
    }
}
```

- [ ] **Step 18: Run migrations and verify**

```bash
php artisan migrate --no-interaction
php artisan test --compact
```

Expected: All tests pass. No migration errors.

- [ ] **Step 19: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add tests/Pest.php app/Models/ database/migrations/ database/factories/
git commit -m "feat: add domain models, migrations, and factories"
```

---

### Task 5: StrategyInterface and MomentumStrategy

**Files:**
- Create: `app/Trading/StrategyInterface.php`
- Create: `app/Trading/Strategies/MomentumStrategy.php`
- Create: `tests/Feature/MomentumStrategyTest.php`

- [ ] **Step 1: Write the failing tests**

```bash
php artisan make:test --pest MomentumStrategyTest --no-interaction
```

Replace `tests/Feature/MomentumStrategyTest.php`:

```php
<?php

use App\Enums\TradeAction;
use App\Models\Persona;
use App\Models\PriceSnapshot;
use App\Models\Position;
use App\Trading\Strategies\MomentumStrategy;
use App\Trading\TradeSignal;

it('returns null when no ticker change exceeds the buy threshold', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create();
    $snapshot = PriceSnapshot::factory()->forTicker('AAPL')->create([
        'price' => 150.0,
        'change_percent' => 0.5,
    ]);

    $strategy = new MomentumStrategy();
    expect($strategy->evaluate($persona, collect([$snapshot])))->toBeNull();
});

it('returns a buy signal when change percent exceeds the buy threshold', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create([
        'strategy_parameters' => [
            'tickers' => ['AAPL'],
            'buy_threshold' => 1.5,
            'sell_threshold' => 2.0,
            'ai_confidence_min' => 0.4,
            'ai_confidence_max' => 0.7,
            'shares_per_trade' => 2,
        ],
    ]);
    $snapshot = PriceSnapshot::factory()->forTicker('AAPL')->create([
        'price' => 150.0,
        'change_percent' => 2.5,
    ]);

    $signal = (new MomentumStrategy())->evaluate($persona, collect([$snapshot]));

    expect($signal)->toBeInstanceOf(TradeSignal::class)
        ->and($signal->ticker)->toBe('AAPL')
        ->and($signal->action)->toBe(TradeAction::Buy)
        ->and($signal->shares)->toBe(2.0);
});

it('returns a sell signal when an open position drops past the sell threshold', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create([
        'strategy_parameters' => [
            'tickers' => ['AAPL'],
            'buy_threshold' => 1.5,
            'sell_threshold' => 2.0,
            'ai_confidence_min' => 0.4,
            'ai_confidence_max' => 0.7,
            'shares_per_trade' => 1,
        ],
    ]);
    Position::factory()->for($persona)->create([
        'ticker' => 'AAPL',
        'shares' => 5.0,
        'average_cost' => 160.0,
    ]);
    $snapshot = PriceSnapshot::factory()->forTicker('AAPL')->create([
        'price' => 144.0,
        'change_percent' => -3.0,
    ]);

    $signal = (new MomentumStrategy())->evaluate($persona, collect([$snapshot]));

    expect($signal)->toBeInstanceOf(TradeSignal::class)
        ->and($signal->ticker)->toBe('AAPL')
        ->and($signal->action)->toBe(TradeAction::Sell)
        ->and($signal->shares)->toBe(5.0);
});

it('sets shouldConsultAI true when confidence falls in the borderline range', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create([
        'strategy_parameters' => [
            'tickers' => ['AAPL'],
            'buy_threshold' => 1.5,
            'sell_threshold' => 2.0,
            'ai_confidence_min' => 0.4,
            'ai_confidence_max' => 0.7,
            'shares_per_trade' => 1,
        ],
    ]);
    // confidence = min(1.8 / (1.5 * 2), 1.0) = min(0.6, 1.0) = 0.6 → inside [0.4, 0.7]
    $snapshot = PriceSnapshot::factory()->forTicker('AAPL')->create([
        'price' => 150.0,
        'change_percent' => 1.8,
    ]);

    $signal = (new MomentumStrategy())->evaluate($persona, collect([$snapshot]));

    expect($signal)->not->toBeNull()
        ->and($signal->shouldConsultAI)->toBeTrue();
});

it('sets shouldConsultAI false when confidence is above the borderline range', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create([
        'strategy_parameters' => [
            'tickers' => ['AAPL'],
            'buy_threshold' => 1.5,
            'sell_threshold' => 2.0,
            'ai_confidence_min' => 0.4,
            'ai_confidence_max' => 0.7,
            'shares_per_trade' => 1,
        ],
    ]);
    // confidence = min(4.5 / 3.0, 1.0) = 1.0 → above 0.7
    $snapshot = PriceSnapshot::factory()->forTicker('AAPL')->create([
        'price' => 150.0,
        'change_percent' => 4.5,
    ]);

    $signal = (new MomentumStrategy())->evaluate($persona, collect([$snapshot]));

    expect($signal)->not->toBeNull()
        ->and($signal->shouldConsultAI)->toBeFalse();
});

it('does not return a buy signal when an open position already exists', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create();
    Position::factory()->for($persona)->create(['ticker' => 'AAPL', 'shares' => 5.0]);
    $snapshot = PriceSnapshot::factory()->forTicker('AAPL')->create([
        'change_percent' => 3.0,
    ]);

    expect((new MomentumStrategy())->evaluate($persona, collect([$snapshot])))->toBeNull();
});
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
php artisan test --compact --filter=MomentumStrategyTest
```

Expected: FAIL — `App\Trading\StrategyInterface` not found.

- [ ] **Step 3: Create StrategyInterface**

```bash
php artisan make:interface Trading/StrategyInterface --no-interaction
```

Replace `app/Trading/StrategyInterface.php`:

```php
<?php

namespace App\Trading;

use App\Models\Persona;
use Illuminate\Support\Collection;

interface StrategyInterface
{
    public function evaluate(Persona $persona, Collection $snapshots): ?TradeSignal;
}
```

- [ ] **Step 4: Create MomentumStrategy**

```bash
mkdir -p app/Trading/Strategies
php artisan make:class Trading/Strategies/MomentumStrategy --no-interaction
```

Replace `app/Trading/Strategies/MomentumStrategy.php`:

```php
<?php

namespace App\Trading\Strategies;

use App\Enums\TradeAction;
use App\Models\Persona;
use App\Trading\StrategyInterface;
use App\Trading\TradeSignal;
use Illuminate\Support\Collection;

class MomentumStrategy implements StrategyInterface
{
    public function evaluate(Persona $persona, Collection $snapshots): ?TradeSignal
    {
        $params = $persona->strategy_parameters;
        $buyThreshold = (float) ($params['buy_threshold'] ?? 1.5);
        $sellThreshold = (float) ($params['sell_threshold'] ?? 2.0);
        $aiMin = (float) ($params['ai_confidence_min'] ?? 0.4);
        $aiMax = (float) ($params['ai_confidence_max'] ?? 0.7);
        $sharesPerTrade = (float) ($params['shares_per_trade'] ?? 1);

        $bestSignal = null;
        $bestConfidence = 0.0;

        foreach ($params['tickers'] ?? [] as $ticker) {
            $snapshot = $snapshots->firstWhere('ticker', $ticker);
            if (! $snapshot) {
                continue;
            }

            $changePercent = (float) $snapshot->change_percent;
            $openPosition = $persona->openPositions()->where('ticker', $ticker)->first();

            if ($openPosition && $changePercent <= -$sellThreshold) {
                $confidence = min(abs($changePercent) / ($sellThreshold * 2), 1.0);
                if ($confidence > $bestConfidence) {
                    $bestConfidence = $confidence;
                    $bestSignal = new TradeSignal(
                        ticker: $ticker,
                        action: TradeAction::Sell,
                        shares: (float) $openPosition->shares,
                        reason: "Price dropped {$changePercent}% (threshold: -{$sellThreshold}%)",
                        confidence: $confidence,
                        shouldConsultAI: $confidence >= $aiMin && $confidence <= $aiMax,
                    );
                }
            } elseif (! $openPosition && $changePercent >= $buyThreshold) {
                $confidence = min($changePercent / ($buyThreshold * 2), 1.0);
                if ($confidence > $bestConfidence) {
                    $bestConfidence = $confidence;
                    $bestSignal = new TradeSignal(
                        ticker: $ticker,
                        action: TradeAction::Buy,
                        shares: $sharesPerTrade,
                        reason: "Price up {$changePercent}% (threshold: {$buyThreshold}%)",
                        confidence: $confidence,
                        shouldConsultAI: $confidence >= $aiMin && $confidence <= $aiMax,
                    );
                }
            }
        }

        return $bestSignal;
    }
}
```

- [ ] **Step 5: Run tests to confirm they pass**

```bash
php artisan test --compact --filter=MomentumStrategyTest
```

Expected: All 6 tests PASS.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint app/Trading/ --format agent
git add app/Trading/ tests/Feature/MomentumStrategyTest.php
git commit -m "feat: add StrategyInterface and MomentumStrategy with tests"
```

---

### Task 6: MarketDataService

**Files:**
- Create: `app/Services/MarketDataService.php`
- Create: `tests/Feature/MarketDataServiceTest.php`
- Modify: `app/Providers/AppServiceProvider.php`

- [ ] **Step 1: Write the failing test**

```bash
php artisan make:test --pest MarketDataServiceTest --no-interaction
```

Replace `tests/Feature/MarketDataServiceTest.php`:

```php
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
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
php artisan test --compact --filter=MarketDataServiceTest
```

Expected: FAIL — `App\Services\MarketDataService` not found.

- [ ] **Step 3: Create MarketDataService**

```bash
php artisan make:class Services/MarketDataService --no-interaction
```

Replace `app/Services/MarketDataService.php`:

```php
<?php

namespace App\Services;

use App\Trading\MarketQuote;
use Illuminate\Support\Collection;
use Scheb\YahooFinanceApi\ApiClient;

class MarketDataService
{
    public function __construct(private readonly ApiClient $client) {}

    public function getQuote(string $ticker): MarketQuote
    {
        $quote = $this->client->getQuote($ticker);

        return new MarketQuote(
            ticker: $ticker,
            price: $quote->getRegularMarketPrice(),
            changePercent: $quote->getRegularMarketChangePercent(),
            fetchedAt: now(),
        );
    }

    /** @return Collection<int, MarketQuote> */
    public function getQuotes(array $tickers): Collection
    {
        return collect($tickers)->map(fn (string $ticker) => $this->getQuote($ticker));
    }
}
```

- [ ] **Step 4: Register the ApiClient and MarketDataService in AppServiceProvider**

Replace `app/Providers/AppServiceProvider.php`:

```php
<?php

namespace App\Providers;

use App\Services\DiscordService;
use App\Services\MarketDataService;
use Illuminate\Support\ServiceProvider;
use Scheb\YahooFinanceApi\ApiClient;
use Scheb\YahooFinanceApi\ApiClientFactory;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ApiClient::class, fn () => ApiClientFactory::createApiClient());

        $this->app->singleton(MarketDataService::class, function () {
            return new MarketDataService(app(ApiClient::class));
        });

        $this->app->singleton(DiscordService::class, function () {
            return new DiscordService(
                token: config('services.discord.token'),
                channelId: config('services.discord.channel_id'),
            );
        });
    }

    public function boot(): void {}
}
```

Note: `DiscordService` is registered here now so it is ready when we implement it in Task 11. Laravel will resolve it lazily, so the missing class won't cause errors until `DiscordService` is actually resolved.

- [ ] **Step 5: Run tests to confirm they pass**

```bash
php artisan test --compact --filter=MarketDataServiceTest
```

Expected: 2 tests PASS.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint app/Services/MarketDataService.php app/Providers/AppServiceProvider.php --format agent
git add app/Services/MarketDataService.php app/Providers/AppServiceProvider.php tests/Feature/MarketDataServiceTest.php
git commit -m "feat: add MarketDataService wrapping Yahoo Finance API"
```

---

### Task 7: ExecuteTradeJob

**Files:**
- Create: `app/Jobs/ExecuteTradeJob.php`
- Create: `tests/Feature/ExecuteTradeJobTest.php`

This job must **never retry** — a duplicate run would double a trade.

- [ ] **Step 1: Write the failing tests**

```bash
php artisan make:test --pest ExecuteTradeJobTest --no-interaction
```

Replace `tests/Feature/ExecuteTradeJobTest.php`:

```php
<?php

use App\Enums\TradeAction;
use App\Jobs\ExecuteTradeJob;
use App\Models\Persona;
use App\Models\Position;
use App\Models\Trade;
use App\Trading\TradeSignal;

it('creates a trade record on a buy', function () {
    $persona = Persona::factory()->create(['cash_balance' => 10000.00]);
    $signal = new TradeSignal(
        ticker: 'AAPL',
        action: TradeAction::Buy,
        shares: 1.0,
        reason: 'Price up 2.5%',
        confidence: 0.9,
        shouldConsultAI: false,
    );

    ExecuteTradeJob::dispatchSync($persona, $signal, 150.00);

    $trade = Trade::first();
    expect($trade)->not->toBeNull()
        ->and($trade->ticker)->toBe('AAPL')
        ->and($trade->action)->toBe(TradeAction::Buy)
        ->and($trade->shares)->toBe('1.0000')
        ->and($trade->price_per_share)->toBe('150.0000')
        ->and($trade->total_value)->toBe('150.00')
        ->and($trade->signal_reason)->toBe('Price up 2.5%')
        ->and($trade->ai_rationale)->toBeNull();
});

it('deducts cash on a buy', function () {
    $persona = Persona::factory()->create(['cash_balance' => 10000.00]);
    $signal = new TradeSignal('AAPL', TradeAction::Buy, 2.0, 'Signal', 0.9, false);

    ExecuteTradeJob::dispatchSync($persona, $signal, 200.00);

    expect($persona->fresh()->cash_balance)->toBe('9600.00');
});

it('creates a new position on the first buy', function () {
    $persona = Persona::factory()->create(['cash_balance' => 10000.00]);
    $signal = new TradeSignal('AAPL', TradeAction::Buy, 3.0, 'Signal', 0.9, false);

    ExecuteTradeJob::dispatchSync($persona, $signal, 100.00);

    $position = Position::where('persona_id', $persona->id)->where('ticker', 'AAPL')->first();
    expect($position->shares)->toBe('3.0000')
        ->and($position->average_cost)->toBe('100.0000');
});

it('updates average cost on a subsequent buy of the same ticker', function () {
    $persona = Persona::factory()->create(['cash_balance' => 10000.00]);
    Position::factory()->for($persona)->create([
        'ticker' => 'AAPL',
        'shares' => 2.0,
        'average_cost' => 100.00,
    ]);
    $signal = new TradeSignal('AAPL', TradeAction::Buy, 2.0, 'Signal', 0.9, false);

    // 2 shares @ $100 + 2 shares @ $120 → avg = $110
    ExecuteTradeJob::dispatchSync($persona, $signal, 120.00);

    $position = Position::where('persona_id', $persona->id)->where('ticker', 'AAPL')->first();
    expect($position->shares)->toBe('4.0000')
        ->and($position->average_cost)->toBe('110.0000');
});

it('credits cash and reduces shares on a sell', function () {
    $persona = Persona::factory()->create(['cash_balance' => 5000.00]);
    Position::factory()->for($persona)->create([
        'ticker' => 'AAPL',
        'shares' => 5.0,
        'average_cost' => 100.00,
    ]);
    $signal = new TradeSignal('AAPL', TradeAction::Sell, 5.0, 'Signal', 0.9, false);

    ExecuteTradeJob::dispatchSync($persona, $signal, 150.00);

    $position = Position::where('persona_id', $persona->id)->where('ticker', 'AAPL')->first();
    expect((float) $position->shares)->toBe(0.0)
        ->and($persona->fresh()->cash_balance)->toBe('5750.00');
});

it('stores ai_rationale when provided', function () {
    $persona = Persona::factory()->create(['cash_balance' => 10000.00]);
    $signal = new TradeSignal('AAPL', TradeAction::Buy, 1.0, 'Algo signal', 0.6, true);

    ExecuteTradeJob::dispatchSync($persona, $signal, 150.00, 'AI approved: strong momentum');

    expect(Trade::first()->ai_rationale)->toBe('AI approved: strong momentum');
});

it('skips a buy when the persona has insufficient cash', function () {
    $persona = Persona::factory()->create(['cash_balance' => 10.00]);
    $signal = new TradeSignal('AAPL', TradeAction::Buy, 1.0, 'Signal', 0.9, false);

    ExecuteTradeJob::dispatchSync($persona, $signal, 150.00);

    expect(Trade::count())->toBe(0);
});

it('sells only available shares when signal requests more than held', function () {
    $persona = Persona::factory()->create(['cash_balance' => 5000.00]);
    Position::factory()->for($persona)->create([
        'ticker' => 'AAPL',
        'shares' => 3.0,
        'average_cost' => 100.00,
    ]);
    $signal = new TradeSignal('AAPL', TradeAction::Sell, 10.0, 'Signal', 0.9, false);

    ExecuteTradeJob::dispatchSync($persona, $signal, 150.00);

    $trade = Trade::first();
    expect((float) $trade->shares)->toBe(3.0)
        ->and($persona->fresh()->cash_balance)->toBe('5450.00');
});
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
php artisan test --compact --filter=ExecuteTradeJobTest
```

Expected: FAIL — `App\Jobs\ExecuteTradeJob` not found.

- [ ] **Step 3: Create ExecuteTradeJob**

```bash
php artisan make:job ExecuteTradeJob --no-interaction
```

Replace `app/Jobs/ExecuteTradeJob.php`:

```php
<?php

namespace App\Jobs;

use App\Enums\TradeAction;
use App\Models\Persona;
use App\Models\Position;
use App\Models\Trade;
use App\Trading\TradeSignal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ExecuteTradeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly Persona $persona,
        public readonly TradeSignal $signal,
        public readonly float $pricePerShare,
        public readonly ?string $aiRationale = null,
    ) {}

    public function handle(): void
    {
        if ($this->signal->action === TradeAction::Buy) {
            $this->executeBuy();
        } else {
            $this->executeSell();
        }
    }

    private function executeBuy(): void
    {
        $totalCost = $this->signal->shares * $this->pricePerShare;

        if ((float) $this->persona->cash_balance < $totalCost) {
            Log::warning('ExecuteTradeJob: insufficient cash', [
                'persona_id' => $this->persona->id,
                'ticker' => $this->signal->ticker,
                'required' => $totalCost,
                'available' => $this->persona->cash_balance,
            ]);

            return;
        }

        $position = $this->persona->positions()
            ->firstOrNew(['ticker' => $this->signal->ticker]);

        if (! $position->exists) {
            $position->average_cost = $this->pricePerShare;
            $position->shares = 0;
            $position->opened_at = now();
        } else {
            $existingShares = (float) $position->shares;
            $existingCost = (float) $position->average_cost;
            $newShares = $this->signal->shares;
            $position->average_cost = (($existingShares * $existingCost) + ($newShares * $this->pricePerShare)) / ($existingShares + $newShares);
        }

        $position->shares = (float) $position->shares + $this->signal->shares;
        $position->save();

        $this->persona->cash_balance = (float) $this->persona->cash_balance - $totalCost;
        $this->persona->save();

        $this->recordTrade($this->signal->shares);
    }

    private function executeSell(): void
    {
        $position = $this->persona->openPositions()
            ->where('ticker', $this->signal->ticker)
            ->first();

        if (! $position) {
            return;
        }

        $sharesToSell = min($this->signal->shares, (float) $position->shares);

        $position->shares = (float) $position->shares - $sharesToSell;
        $position->save();

        $this->persona->cash_balance = (float) $this->persona->cash_balance + ($sharesToSell * $this->pricePerShare);
        $this->persona->save();

        $this->recordTrade($sharesToSell);
    }

    private function recordTrade(float $shares): void
    {
        Trade::create([
            'persona_id' => $this->persona->id,
            'ticker' => $this->signal->ticker,
            'action' => $this->signal->action,
            'shares' => $shares,
            'price_per_share' => $this->pricePerShare,
            'total_value' => round($shares * $this->pricePerShare, 2),
            'signal_reason' => $this->signal->reason,
            'ai_rationale' => $this->aiRationale,
            'executed_at' => now(),
        ]);
    }
}
```

- [ ] **Step 4: Run tests to confirm they pass**

```bash
php artisan test --compact --filter=ExecuteTradeJobTest
```

Expected: All 8 tests PASS.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint app/Jobs/ExecuteTradeJob.php --format agent
git add app/Jobs/ExecuteTradeJob.php tests/Feature/ExecuteTradeJobTest.php
git commit -m "feat: add ExecuteTradeJob with buy/sell position management"
```

---

### Task 8: AIEvaluator service

**Files:**
- Create: `app/Services/AIEvaluator.php`
- Create: `tests/Feature/AIEvaluatorTest.php`

- [ ] **Step 1: Write the failing tests**

```bash
php artisan make:test --pest AIEvaluatorTest --no-interaction
```

Replace `tests/Feature/AIEvaluatorTest.php`:

```php
<?php

use App\Enums\TradeAction;
use App\Models\Persona;
use App\Models\PriceSnapshot;
use App\Services\AIEvaluator;
use App\Trading\TradeSignal;
use Illuminate\Support\Facades\Http;

it('returns the original signal when AI approves', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'text' => json_encode(['decision' => 'approve', 'rationale' => 'Strong momentum confirmed.']),
            ]],
        ]),
    ]);

    $persona = Persona::factory()->create();
    $snapshot = PriceSnapshot::factory()->forTicker('AAPL')->create(['price' => 150.0, 'change_percent' => 1.8]);
    $signal = new TradeSignal('AAPL', TradeAction::Buy, 1.0, 'Algo signal', 0.6, true);

    [$resolvedSignal, $rationale] = app(AIEvaluator::class)->evaluate($persona, $signal, $snapshot);

    expect($resolvedSignal->ticker)->toBe('AAPL')
        ->and($resolvedSignal->action)->toBe(TradeAction::Buy)
        ->and($resolvedSignal->shares)->toBe(1.0)
        ->and($rationale)->toBe('Strong momentum confirmed.');
});

it('returns a modified signal when AI suggests different share count', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'text' => json_encode(['decision' => 'modify', 'shares' => 2.0, 'rationale' => 'Increase position size.']),
            ]],
        ]),
    ]);

    $persona = Persona::factory()->create();
    $snapshot = PriceSnapshot::factory()->forTicker('AAPL')->create(['price' => 150.0, 'change_percent' => 1.8]);
    $signal = new TradeSignal('AAPL', TradeAction::Buy, 1.0, 'Algo signal', 0.6, true);

    [$resolvedSignal, $rationale] = app(AIEvaluator::class)->evaluate($persona, $signal, $snapshot);

    expect((float) $resolvedSignal->shares)->toBe(2.0)
        ->and($rationale)->toBe('Increase position size.');
});

it('returns null when AI rejects the signal', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'text' => json_encode(['decision' => 'reject', 'rationale' => 'Insufficient volume.']),
            ]],
        ]),
    ]);

    $persona = Persona::factory()->create();
    $snapshot = PriceSnapshot::factory()->forTicker('AAPL')->create(['price' => 150.0, 'change_percent' => 1.8]);
    $signal = new TradeSignal('AAPL', TradeAction::Buy, 1.0, 'Algo signal', 0.6, true);

    $result = app(AIEvaluator::class)->evaluate($persona, $signal, $snapshot);

    expect($result)->toBeNull();
});
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
php artisan test --compact --filter=AIEvaluatorTest
```

Expected: FAIL — `App\Services\AIEvaluator` not found.

- [ ] **Step 3: Create AIEvaluator**

```bash
php artisan make:class Services/AIEvaluator --no-interaction
```

Replace `app/Services/AIEvaluator.php`:

```php
<?php

namespace App\Services;

use App\Models\Persona;
use App\Models\PriceSnapshot;
use App\Trading\TradeSignal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIEvaluator
{
    /**
     * Evaluate a trade signal using the Claude API.
     *
     * @return array{0: TradeSignal, 1: string}|null Returns [signal, rationale] or null if rejected.
     */
    public function evaluate(Persona $persona, TradeSignal $signal, PriceSnapshot $snapshot): ?array
    {
        $prompt = $this->buildPrompt($persona, $signal, $snapshot);

        $response = Http::withHeaders([
            'x-api-key' => config('services.anthropic.key'),
            'anthropic-version' => config('services.anthropic.version'),
            'content-type' => 'application/json',
        ])->post('https://api.anthropic.com/v1/messages', [
            'model' => config('services.anthropic.model'),
            'max_tokens' => 256,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]);

        $text = $response->json('content.0.text', '');
        $parsed = json_decode($text, true);

        if (! is_array($parsed) || ! isset($parsed['decision'])) {
            Log::warning('AIEvaluator: unparseable response', ['response' => $text]);

            return null;
        }

        return match ($parsed['decision']) {
            'approve' => [$signal, $parsed['rationale'] ?? ''],
            'modify' => [
                new TradeSignal(
                    ticker: $signal->ticker,
                    action: $signal->action,
                    shares: (float) ($parsed['shares'] ?? $signal->shares),
                    reason: $signal->reason,
                    confidence: $signal->confidence,
                    shouldConsultAI: false,
                ),
                $parsed['rationale'] ?? '',
            ],
            default => null,
        };
    }

    private function buildPrompt(Persona $persona, TradeSignal $signal, PriceSnapshot $snapshot): string
    {
        $openPositions = $persona->openPositions()->where('ticker', $signal->ticker)->first();
        $currentPosition = $openPositions
            ? "{$openPositions->shares} shares @ avg \${$openPositions->average_cost}"
            : 'none';

        return <<<PROMPT
You are evaluating a trade signal for a paper trading bot.

Persona: {$persona->name} ({$persona->strategy_type->value} strategy)
Cash balance: \${$persona->cash_balance}
Current position in {$signal->ticker}: {$currentPosition}

Trade signal:
- Action: {$signal->action->value}
- Ticker: {$signal->ticker}
- Shares: {$signal->shares}
- Current price: \${$snapshot->price}
- Intraday change: {$snapshot->change_percent}%
- Algorithm confidence: {$signal->confidence}
- Algorithm reason: {$signal->reason}

Respond with a JSON object only — no other text:
{"decision": "approve" | "modify" | "reject", "shares": <number if modifying>, "rationale": "<brief explanation>"}
PROMPT;
    }
}
```

- [ ] **Step 4: Run tests to confirm they pass**

```bash
php artisan test --compact --filter=AIEvaluatorTest
```

Expected: All 3 tests PASS.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint app/Services/AIEvaluator.php --format agent
git add app/Services/AIEvaluator.php tests/Feature/AIEvaluatorTest.php
git commit -m "feat: add AIEvaluator service wrapping Anthropic API"
```

---

### Task 9: EvaluatePersonaJob

**Files:**
- Create: `app/Jobs/EvaluatePersonaJob.php`
- Create: `tests/Feature/EvaluatePersonaJobTest.php`

This is the main orchestrator. It fetches prices, runs the strategy, and dispatches the next job in the pipeline.

- [ ] **Step 1: Write the failing tests**

```bash
php artisan make:test --pest EvaluatePersonaJobTest --no-interaction
```

Replace `tests/Feature/EvaluatePersonaJobTest.php`:

```php
<?php

use App\Enums\TradeAction;
use App\Jobs\AIEvaluationJob;
use App\Jobs\EvaluatePersonaJob;
use App\Jobs\ExecuteTradeJob;
use App\Models\Persona;
use App\Models\PriceSnapshot;
use App\Services\MarketDataService;
use App\Trading\MarketQuote;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

it('dispatches ExecuteTradeJob directly for a high-confidence signal', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create([
        'strategy_parameters' => [
            'tickers' => ['AAPL'],
            'buy_threshold' => 1.5,
            'sell_threshold' => 2.0,
            'ai_confidence_min' => 0.4,
            'ai_confidence_max' => 0.7,
            'shares_per_trade' => 1,
        ],
    ]);

    // confidence = min(4.5 / 3.0, 1.0) = 1.0 → not in [0.4, 0.7], skips AI
    $mockService = Mockery::mock(MarketDataService::class);
    $mockService->shouldReceive('getQuote')
        ->with('AAPL')
        ->andReturn(new MarketQuote('AAPL', 150.0, 4.5, now()));
    $this->app->instance(MarketDataService::class, $mockService);

    EvaluatePersonaJob::dispatchSync($persona);

    Queue::assertPushed(ExecuteTradeJob::class, fn ($job) =>
        $job->signal->ticker === 'AAPL' &&
        $job->signal->action === TradeAction::Buy
    );
    Queue::assertNotPushed(AIEvaluationJob::class);
});

it('dispatches AIEvaluationJob for a borderline-confidence signal', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create([
        'strategy_parameters' => [
            'tickers' => ['AAPL'],
            'buy_threshold' => 1.5,
            'sell_threshold' => 2.0,
            'ai_confidence_min' => 0.4,
            'ai_confidence_max' => 0.7,
            'shares_per_trade' => 1,
        ],
    ]);

    // confidence = min(1.8 / 3.0, 1.0) = 0.6 → inside [0.4, 0.7], goes to AI
    $mockService = Mockery::mock(MarketDataService::class);
    $mockService->shouldReceive('getQuote')
        ->with('AAPL')
        ->andReturn(new MarketQuote('AAPL', 150.0, 1.8, now()));
    $this->app->instance(MarketDataService::class, $mockService);

    EvaluatePersonaJob::dispatchSync($persona);

    Queue::assertPushed(AIEvaluationJob::class);
    Queue::assertNotPushed(ExecuteTradeJob::class);
});

it('dispatches nothing when strategy returns no signal', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create();

    // change_percent 0.5 is below buy_threshold 1.5
    $mockService = Mockery::mock(MarketDataService::class);
    $mockService->shouldReceive('getQuote')
        ->with('AAPL')
        ->andReturn(new MarketQuote('AAPL', 150.0, 0.5, now()));
    $this->app->instance(MarketDataService::class, $mockService);

    EvaluatePersonaJob::dispatchSync($persona);

    Queue::assertNothingPushed();
});

it('reuses an existing PriceSnapshot within the polling window', function () {
    $persona = Persona::factory()->withTickers(['AAPL'])->create();
    PriceSnapshot::factory()->forTicker('AAPL')->create([
        'price' => 150.0,
        'change_percent' => 0.5,
        'fetched_at' => now()->subMinutes(5),
    ]);

    $mockService = Mockery::mock(MarketDataService::class);
    $mockService->shouldNotReceive('getQuote');
    $this->app->instance(MarketDataService::class, $mockService);

    EvaluatePersonaJob::dispatchSync($persona);
});
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
php artisan test --compact --filter=EvaluatePersonaJobTest
```

Expected: FAIL — `App\Jobs\EvaluatePersonaJob` not found.

- [ ] **Step 3: Create EvaluatePersonaJob**

```bash
php artisan make:job EvaluatePersonaJob --no-interaction
```

Replace `app/Jobs/EvaluatePersonaJob.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Persona;
use App\Models\PriceSnapshot;
use App\Services\MarketDataService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class EvaluatePersonaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly Persona $persona) {}

    public function handle(MarketDataService $marketDataService): void
    {
        $tickers = $this->persona->strategy_parameters['tickers'] ?? [];

        if (empty($tickers)) {
            return;
        }

        try {
            $snapshots = $this->getOrFetchSnapshots($tickers, $marketDataService);
        } catch (\Throwable $e) {
            Log::warning('EvaluatePersonaJob: market data fetch failed', [
                'persona_id' => $this->persona->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $strategy = $this->persona->strategy_type->make();
        $signal = $strategy->evaluate($this->persona, $snapshots);

        if (! $signal) {
            return;
        }

        $snapshot = $snapshots->firstWhere('ticker', $signal->ticker);

        if ($signal->shouldConsultAI) {
            AIEvaluationJob::dispatch($this->persona, $signal, $snapshot);
        } else {
            ExecuteTradeJob::dispatch($this->persona, $signal, (float) $snapshot->price);
        }
    }

    private function getOrFetchSnapshots(array $tickers, MarketDataService $marketDataService): Collection
    {
        $cutoff = now()->subMinutes(15);

        return collect($tickers)->map(function (string $ticker) use ($cutoff, $marketDataService) {
            $existing = PriceSnapshot::where('ticker', $ticker)
                ->where('fetched_at', '>=', $cutoff)
                ->latest('fetched_at')
                ->first();

            if ($existing) {
                return $existing;
            }

            $quote = $marketDataService->getQuote($ticker);

            return PriceSnapshot::create([
                'ticker' => $quote->ticker,
                'price' => $quote->price,
                'change_percent' => $quote->changePercent,
                'fetched_at' => $quote->fetchedAt,
            ]);
        });
    }
}
```

- [ ] **Step 4: Run tests to confirm they pass**

```bash
php artisan test --compact --filter=EvaluatePersonaJobTest
```

Expected: All 4 tests PASS.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint app/Jobs/EvaluatePersonaJob.php --format agent
git add app/Jobs/EvaluatePersonaJob.php tests/Feature/EvaluatePersonaJobTest.php
git commit -m "feat: add EvaluatePersonaJob — main pipeline orchestrator"
```

---

### Task 10: AIEvaluationJob

**Files:**
- Create: `app/Jobs/AIEvaluationJob.php`
- Create: `tests/Feature/AIEvaluationJobTest.php`

- [ ] **Step 1: Write the failing tests**

```bash
php artisan make:test --pest AIEvaluationJobTest --no-interaction
```

Replace `tests/Feature/AIEvaluationJobTest.php`:

```php
<?php

use App\Enums\TradeAction;
use App\Jobs\AIEvaluationJob;
use App\Jobs\ExecuteTradeJob;
use App\Models\Persona;
use App\Models\PriceSnapshot;
use App\Services\AIEvaluator;
use App\Trading\TradeSignal;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

it('dispatches ExecuteTradeJob when AI approves the signal', function () {
    $persona = Persona::factory()->create();
    $snapshot = PriceSnapshot::factory()->forTicker('AAPL')->create(['price' => 150.0]);
    $signal = new TradeSignal('AAPL', TradeAction::Buy, 1.0, 'Algo signal', 0.6, true);

    $mockEvaluator = Mockery::mock(AIEvaluator::class);
    $mockEvaluator->shouldReceive('evaluate')
        ->once()
        ->andReturn([$signal, 'Strong momentum confirmed.']);
    $this->app->instance(AIEvaluator::class, $mockEvaluator);

    AIEvaluationJob::dispatchSync($persona, $signal, $snapshot);

    Queue::assertPushed(ExecuteTradeJob::class, fn ($job) =>
        $job->signal->ticker === 'AAPL' &&
        $job->pricePerShare === 150.0 &&
        $job->aiRationale === 'Strong momentum confirmed.'
    );
});

it('does not dispatch ExecuteTradeJob when AI rejects the signal', function () {
    $persona = Persona::factory()->create();
    $snapshot = PriceSnapshot::factory()->forTicker('AAPL')->create(['price' => 150.0]);
    $signal = new TradeSignal('AAPL', TradeAction::Buy, 1.0, 'Algo signal', 0.6, true);

    $mockEvaluator = Mockery::mock(AIEvaluator::class);
    $mockEvaluator->shouldReceive('evaluate')->once()->andReturn(null);
    $this->app->instance(AIEvaluator::class, $mockEvaluator);

    AIEvaluationJob::dispatchSync($persona, $signal, $snapshot);

    Queue::assertNothingPushed();
});
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
php artisan test --compact --filter=AIEvaluationJobTest
```

Expected: FAIL — `App\Jobs\AIEvaluationJob` not found.

- [ ] **Step 3: Create AIEvaluationJob**

```bash
php artisan make:job AIEvaluationJob --no-interaction
```

Replace `app/Jobs/AIEvaluationJob.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Persona;
use App\Models\PriceSnapshot;
use App\Services\AIEvaluator;
use App\Trading\TradeSignal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class AIEvaluationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly Persona $persona,
        public readonly TradeSignal $signal,
        public readonly PriceSnapshot $snapshot,
    ) {}

    public function handle(AIEvaluator $evaluator): void
    {
        $result = $evaluator->evaluate($this->persona, $this->signal, $this->snapshot);

        if (! $result) {
            Log::info('AIEvaluationJob: signal rejected by AI', [
                'persona_id' => $this->persona->id,
                'ticker' => $this->signal->ticker,
            ]);

            return;
        }

        [$resolvedSignal, $rationale] = $result;

        ExecuteTradeJob::dispatch(
            $this->persona,
            $resolvedSignal,
            (float) $this->snapshot->price,
            $rationale,
        );
    }
}
```

- [ ] **Step 4: Run tests to confirm they pass**

```bash
php artisan test --compact --filter=AIEvaluationJobTest
```

Expected: Both tests PASS.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint app/Jobs/AIEvaluationJob.php --format agent
git add app/Jobs/AIEvaluationJob.php tests/Feature/AIEvaluationJobTest.php
git commit -m "feat: add AIEvaluationJob routing between AIEvaluator and ExecuteTradeJob"
```

---

### Task 11: DiscordService

**Files:**
- Create: `app/Services/DiscordService.php`
- Create: `tests/Feature/DiscordServiceTest.php`

- [ ] **Step 1: Write the failing tests**

```bash
php artisan make:test --pest DiscordServiceTest --no-interaction
```

Replace `tests/Feature/DiscordServiceTest.php`:

```php
<?php

use App\Services\DiscordService;
use Illuminate\Support\Facades\Http;

it('posts a message with embeds to the configured Discord channel', function () {
    Http::fake([
        'discord.com/*' => Http::response([], 200),
    ]);

    $service = new DiscordService('test-token', '123456789');

    $service->postMessage([
        ['title' => 'Weekly Report', 'description' => 'Summary here.'],
    ]);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/channels/123456789/messages')
            && $request->hasHeader('Authorization', 'Bot test-token')
            && isset($request->data()['embeds']);
    });
});

it('throws when Discord returns an error', function () {
    Http::fake([
        'discord.com/*' => Http::response(['message' => 'Unknown Channel'], 404),
    ]);

    $service = new DiscordService('test-token', '999');

    expect(fn () => $service->postMessage([['title' => 'Test']]))->toThrow(\Exception::class);
});
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
php artisan test --compact --filter=DiscordServiceTest
```

Expected: FAIL — `App\Services\DiscordService` not found.

- [ ] **Step 3: Create DiscordService**

```bash
php artisan make:class Services/DiscordService --no-interaction
```

Replace `app/Services/DiscordService.php`:

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class DiscordService
{
    public function __construct(
        private readonly string $token,
        private readonly string $channelId,
    ) {}

    /** @param array<int, array<string, mixed>> $embeds */
    public function postMessage(array $embeds): void
    {
        Http::withHeaders([
            'Authorization' => "Bot {$this->token}",
            'Content-Type' => 'application/json',
        ])->post("https://discord.com/api/v10/channels/{$this->channelId}/messages", [
            'embeds' => $embeds,
        ])->throw();
    }
}
```

- [ ] **Step 4: Run tests to confirm they pass**

```bash
php artisan test --compact --filter=DiscordServiceTest
```

Expected: Both tests PASS.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint app/Services/DiscordService.php --format agent
git add app/Services/DiscordService.php tests/Feature/DiscordServiceTest.php
git commit -m "feat: add DiscordService posting via bot token and REST API"
```

---

### Task 12: PostWeeklyReportJob

**Files:**
- Create: `app/Jobs/PostWeeklyReportJob.php`
- Create: `tests/Feature/PostWeeklyReportJobTest.php`

- [ ] **Step 1: Write the failing tests**

```bash
php artisan make:test --pest PostWeeklyReportJobTest --no-interaction
```

Replace `tests/Feature/PostWeeklyReportJobTest.php`:

```php
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
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
php artisan test --compact --filter=PostWeeklyReportJobTest
```

Expected: FAIL — `App\Jobs\PostWeeklyReportJob` not found.

- [ ] **Step 3: Create PostWeeklyReportJob**

```bash
php artisan make:job PostWeeklyReportJob --no-interaction
```

Replace `app/Jobs/PostWeeklyReportJob.php`:

```php
<?php

namespace App\Jobs;

use App\Models\DiscordReport;
use App\Models\Persona;
use App\Models\PriceSnapshot;
use App\Services\DiscordService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PostWeeklyReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function handle(DiscordService $discord): void
    {
        $periodEnd = now();
        $periodStart = $periodEnd->copy()->subWeek();

        $personas = Persona::where('is_active', true)->with(['trades', 'openPositions'])->get();

        $fields = $personas->map(fn (Persona $persona) => $this->buildPersonaField($persona, $periodStart, $periodEnd));

        $embeds = [[
            'title' => '📊 Weekly Trading Report',
            'description' => 'Period: ' . $periodStart->format('M j') . ' – ' . $periodEnd->format('M j, Y'),
            'fields' => $fields->toArray(),
            'color' => 3066993,
            'timestamp' => $periodEnd->toIso8601String(),
        ]];

        $discord->postMessage($embeds);

        DiscordReport::create([
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'payload' => ['embeds' => $embeds],
            'posted_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function buildPersonaField(Persona $persona, Carbon $periodStart, Carbon $periodEnd): array
    {
        $weeklyTrades = $persona->trades()
            ->whereBetween('executed_at', [$periodStart, $periodEnd])
            ->get();

        $buyCount = $weeklyTrades->where('action.value', 'buy')->count();
        $sellCount = $weeklyTrades->where('action.value', 'sell')->count();

        $positionLines = $persona->openPositions->map(function ($position) {
            $snapshot = PriceSnapshot::where('ticker', $position->ticker)
                ->latest('fetched_at')
                ->first();

            if (! $snapshot) {
                return "• {$position->ticker}: {$position->shares} shares (no price data)";
            }

            $currentValue = (float) $position->shares * (float) $snapshot->price;
            $unrealisedPnl = ((float) $snapshot->price - (float) $position->average_cost) * (float) $position->shares;
            $sign = $unrealisedPnl >= 0 ? '+' : '';

            return "• {$position->ticker}: {$position->shares} × \${$snapshot->price} = \${$currentValue} ({$sign}" . round($unrealisedPnl, 2) . ')';
        })->join("\n");

        $lines = [
            "**Cash:** \${$persona->cash_balance}",
            "**Trades this week:** {$weeklyTrades->count()} ({$buyCount} buys, {$sellCount} sells)",
        ];

        if ($positionLines) {
            $lines[] = '';
            $lines[] = '**Open Positions:**';
            $lines[] = $positionLines;
        }

        return [
            'name' => '🤖 ' . $persona->name,
            'value' => implode("\n", $lines),
            'inline' => false,
        ];
    }
}
```

- [ ] **Step 4: Run tests to confirm they pass**

```bash
php artisan test --compact --filter=PostWeeklyReportJobTest
```

Expected: All 3 tests PASS.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint app/Jobs/PostWeeklyReportJob.php --format agent
git add app/Jobs/PostWeeklyReportJob.php tests/Feature/PostWeeklyReportJobTest.php
git commit -m "feat: add PostWeeklyReportJob building and posting weekly Discord embed"
```

---

### Task 13: Scheduler configuration

**Files:**
- Modify: `routes/console.php`

- [ ] **Step 1: Write the scheduler**

Replace `routes/console.php`:

```php
<?php

use App\Jobs\EvaluatePersonaJob;
use App\Jobs\PostWeeklyReportJob;
use App\Models\Persona;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Foundation\Inspiring;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Dispatch one evaluation job per active persona every 15 minutes during NYSE hours.
Schedule::call(function () {
    Persona::where('is_active', true)
        ->each(fn (Persona $persona) => EvaluatePersonaJob::dispatch($persona));
})
    ->everyFifteenMinutes()
    ->weekdays()
    ->between('9:30', '16:00')
    ->timezone('America/New_York')
    ->name('trading:evaluate-personas')
    ->withoutOverlapping();

// Post weekly summary every Friday at noon MT.
Schedule::job(new PostWeeklyReportJob())
    ->weeklyOn(5, '12:00')
    ->timezone('America/Edmonton')
    ->name('trading:weekly-report')
    ->withoutOverlapping();
```

- [ ] **Step 2: Verify the schedule is registered**

```bash
php artisan schedule:list
```

Expected: Both `trading:evaluate-personas` and `trading:weekly-report` appear in the list with their correct intervals and timezones.

- [ ] **Step 3: Run the full test suite**

```bash
php artisan test --compact
```

Expected: All tests pass.

- [ ] **Step 4: Format and commit**

```bash
vendor/bin/pint routes/console.php --format agent
git add routes/console.php
git commit -m "feat: wire up scheduler for intraday evaluation and weekly Discord report"
```

---

## Self-Review

**Spec coverage check:**

| Spec requirement | Task |
|---|---|
| Local mock positions in DB | Task 4 (migrations/models), Task 7 (ExecuteTradeJob) |
| Stocks/ETFs only (no special asset types) | Task 4 (flat position schema) |
| Hybrid personas (PHP strategy types + DB instances) | Task 2 (StrategyType enum), Task 4 (Persona model), Task 5 (StrategyInterface) |
| Intraday polling on fixed interval | Task 13 (scheduler, 15min) |
| Yahoo Finance via scheb/yahoo-finance-api | Task 1 (install), Task 6 (MarketDataService) |
| AI as signal layer (algo runs first, AI conditional) | Task 9 (EvaluatePersonaJob shouldConsultAI branch) |
| ExecuteTradeJob never retries | Task 7 (`$tries = 1`) |
| PriceSnapshot deduplication within polling window | Task 9 (`getOrFetchSnapshots`) |
| Discord via bot token + REST API | Task 11 (DiscordService) |
| Weekly Friday noon MT report | Task 13 (scheduler weeklyOn) |
| DiscordReport log | Task 12 (PostWeeklyReportJob creates record) |
| MarketDataService wrapper (no direct library calls) | Task 6 |
| DISCORD_BOT_TOKEN / DISCORD_CHANNEL_ID env vars | Task 1 |
| ANTHROPIC_API_KEY env var | Task 1 |
| NYSE market hours guard | Task 13 (`->between('9:30', '16:00')->timezone('America/New_York')`) |

All spec requirements are covered.

**Placeholder scan:** None found. All steps contain complete code.

**Type consistency check:**
- `TradeSignal` constructor is consistent across Tasks 3, 5, 7, 8, 9, 10 ✓
- `ExecuteTradeJob` constructor signature `(Persona, TradeSignal, float, ?string)` is consistent across Tasks 7, 9, 10 ✓
- `AIEvaluator::evaluate` returns `?array{0: TradeSignal, 1: string}` — consumed correctly in Tasks 8 and 10 ✓
- `DiscordService::postMessage(array $embeds): void` called consistently in Tasks 11 and 12 ✓
- `PriceSnapshotFactory::forTicker(string)` state used in Tasks 5, 6, 8, 9, 10, 12 ✓
