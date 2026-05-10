# Autotrader — System Design

**Date:** 2026-05-10
**Status:** Approved

## Overview

A Laravel-based paper trading bot for learning purposes. The system runs multiple strategy *personas*, each with its own cash balance, watchlist, and trading logic. On a recurring intraday schedule, each persona evaluates market prices, optionally consults an AI layer, and executes paper trades stored entirely in the local database. A weekly Discord summary reports each persona's positions and P&L.

**Key constraints:**
- Paper trade only — no real money, no external brokerage integration
- Stocks and ETFs only
- Intraday polling on a fixed interval (e.g. every 15 minutes during market hours)
- Market data via `scheb/yahoo-finance-api` (unofficial Yahoo Finance PHP client)
- AI layer via Claude API (Anthropic SDK)
- Discord integration via webhook (one-way, weekly)

---

## Domain Models

### `Persona`
A trading profile instance. Strategy type references a PHP class; parameters allow multiple personas to share a strategy type with different configurations.

| Field | Type | Notes |
|-------|------|-------|
| `id` | bigint | |
| `name` | string | e.g. "Aggressive Momentum" |
| `description` | text nullable | |
| `cash_balance` | decimal(15,2) | Starting and current available cash |
| `strategy_type` | string (enum) | Maps to a `StrategyType` PHP enum case |
| `strategy_parameters` | JSON | Includes `tickers` array, thresholds, risk config |
| `is_active` | boolean | Whether this persona participates in polling cycles |
| timestamps | | |

### `Position`
An open holding in a persona's portfolio. Updated on every buy/sell.

| Field | Type | Notes |
|-------|------|-------|
| `id` | bigint | |
| `persona_id` | FK | |
| `ticker` | string | e.g. "AAPL" |
| `shares` | decimal(15,4) | Supports fractional shares |
| `average_cost` | decimal(10,4) | Volume-weighted average cost per share |
| `opened_at` | timestamp | When the first shares were acquired |
| timestamps | | |

Positions with `shares = 0` are retained as historical records (not deleted).

### `Trade`
Immutable record of every executed buy or sell action.

| Field | Type | Notes |
|-------|------|-------|
| `id` | bigint | |
| `persona_id` | FK | |
| `ticker` | string | |
| `action` | enum | `Buy` / `Sell` |
| `shares` | decimal(15,4) | |
| `price_per_share` | decimal(10,4) | Price at time of execution |
| `total_value` | decimal(15,2) | `shares × price_per_share` |
| `signal_reason` | text | Human-readable algo rationale |
| `ai_rationale` | text nullable | Populated only when AI was consulted |
| `executed_at` | timestamp | |
| timestamps | | |

### `PriceSnapshot`
Cached price data per polling cycle. Prevents redundant Yahoo Finance calls when multiple personas watch the same ticker.

| Field | Type | Notes |
|-------|------|-------|
| `id` | bigint | |
| `ticker` | string | |
| `price` | decimal(10,4) | Current price |
| `change_percent` | decimal(6,4) | Intraday % change |
| `fetched_at` | timestamp | Used to determine staleness within a polling window |
| timestamps | | |

### `DiscordReport`
Log of every weekly summary posted to Discord.

| Field | Type | Notes |
|-------|------|-------|
| `id` | bigint | |
| `period_start` | timestamp | |
| `period_end` | timestamp | |
| `payload` | JSON | Full message payload sent to Discord |
| `posted_at` | timestamp | |
| timestamps | | |

---

## Strategy Architecture

Strategy *types* are PHP classes; persona *instances* reference them by enum value. This keeps logic in testable PHP while allowing runtime configuration of parameters.

### `StrategyType` Enum
```php
enum StrategyType: string {
    case Momentum = 'momentum';
    case MeanReversion = 'mean_reversion';
    // Add new types here as new strategy classes are built
}
```

Each case resolves to a concrete class via a `strategyClass(): string` method.

### `StrategyInterface`
```php
interface StrategyInterface
{
    public function evaluate(Persona $persona, Collection $snapshots): ?TradeSignal;
}
```

### `TradeSignal` Value Object
```php
readonly class TradeSignal
{
    public function __construct(
        public string $ticker,
        public TradeAction $action,       // Buy / Sell / Hold
        public float $shares,
        public string $reason,            // Algo rationale for Trade record
        public float $confidence,         // 0.0–1.0
        public bool $shouldConsultAI,     // True when confidence is in borderline range
    ) {}
}
```

Each strategy sets its own `shouldConsultAI` threshold. For example, a momentum strategy might consult AI when confidence is between 0.4 and 0.7.

### `AIEvaluator` Service
Accepts a `TradeSignal` and `Persona`, builds a structured prompt with market context, calls the Claude API, and returns an approved/modified/rejected `TradeSignal`. The `ai_rationale` string from the response is stored on the resulting `Trade` record.

---

## Decision Pipeline

```
Scheduler (every 15min, Mon–Fri during NYSE market hours 9:30am–4:00pm ET)
  └─ dispatches EvaluatePersonaJob × (active personas)
        ├─ fetches/reuses PriceSnapshots for persona's watchlist tickers
        ├─ instantiates strategy class via StrategyType::from($persona->strategy_type)
        ├─ calls strategy->evaluate() → TradeSignal|null
        │
        ├─ [null signal] → no-op, job ends
        ├─ [shouldConsultAI = false] → dispatches ExecuteTradeJob directly
        └─ [shouldConsultAI = true] → dispatches AIEvaluationJob
              └─ calls AIEvaluator (Claude API)
                    ├─ [approved/modified] → dispatches ExecuteTradeJob
                    └─ [rejected] → no-op, logs rejection with rationale

ExecuteTradeJob
  ├─ creates Trade record (with signal_reason + optional ai_rationale)
  ├─ updates or creates Position (adjusts shares + recalculates average_cost)
  └─ deducts (Buy) or credits (Sell) Persona cash_balance
```

### Market Hours Guard
`EvaluatePersonaJob` checks whether the current UTC time falls within NYSE market hours before proceeding. Timezone conversion uses `America/New_York`.

### PriceSnapshot Deduplication
Before calling Yahoo Finance, the job checks for a `PriceSnapshot` with `fetched_at` within the current polling window (e.g. within the last 15 minutes). If found, it reuses it. This keeps API calls proportional to unique tickers, not total personas.

### Weekly Discord Report
`PostWeeklyReportJob` is scheduled for every Friday at 12:00pm `America/Edmonton`. It:
1. Queries `Trade` records from the past 7 days
2. Queries current `Position` records with unrealised P&L (using latest `PriceSnapshot`)
3. Builds a per-persona summary (trades made, realised gains/losses, unrealised positions)
4. Posts to the Discord webhook URL configured in `services.discord.webhook_url`
5. Logs a `DiscordReport` record

---

## Error Handling

| Scenario | Behaviour |
|----------|-----------|
| Yahoo Finance timeout / bad response | Job logs warning, persona skips this cycle. No retry (data will be fresh next cycle). |
| Claude API failure in `AIEvaluationJob` | Retries up to 3 times with exponential backoff. If all retries fail, signal is discarded and failure is logged. |
| `ExecuteTradeJob` failure | Does **not** retry automatically (would duplicate trades). Logs error and fires a `TradeExecutionFailed` event for future alerting. |
| Discord webhook failure in `PostWeeklyReportJob` | Retries up to 3 times. `DiscordReport` record is only written on success. |

---

## Testing Strategy

- **Strategy classes** (`MomentumStrategy`, etc.) — unit tested with fabricated `PriceSnapshot` collections. No DB. Assert correct `TradeSignal` output for given inputs.
- **`EvaluatePersonaJob`** — feature tested with faked Yahoo Finance responses and a mocked `AIEvaluator`. Assert correct job dispatches.
- **`AIEvaluationJob`** — feature tested with a mocked Claude API response. Assert `ExecuteTradeJob` is dispatched with correctly modified signal.
- **`ExecuteTradeJob`** — feature tested against a real SQLite test DB. Assert `Trade` created, `Position` updated, `Persona` `cash_balance` adjusted correctly.
- **`PostWeeklyReportJob`** — feature tested with seeded `Trade`/`Position` data. Assert correct Discord webhook payload is built. HTTP call is faked via `Http::fake()`.
- **Market hours guard** — unit tested with fixed timestamps covering open, closed, and boundary cases.

---

## Integrations

### Yahoo Finance (`scheb/yahoo-finance-api`)
- Wrapped in a `MarketDataService` class injected via the service container
- Returns typed DTOs, not raw API responses, to keep strategy code decoupled from the library
- Configured via `services.yahoo_finance` config key if any auth/options are needed

### Claude API (Anthropic SDK)
- Wrapped in `AIEvaluator` service
- Prompt includes: persona name, strategy type, current positions, trade signal, price context
- Response is parsed for action (approve/modify/reject) and rationale string
- API key stored in `ANTHROPIC_API_KEY` env variable

### Discord Webhook
- No Discord bot or OAuth — simple incoming webhook POST
- Webhook URL stored in `DISCORD_WEBHOOK_URL` env variable, accessed via `services.discord.webhook_url`
- Payload formatted as a Discord embed with per-persona sections

---

## CLAUDE.md Additions (see main file)

The following sections should be added to CLAUDE.md:
- Project overview and purpose
- Domain model glossary
- Architecture patterns used in this codebase
- Integration notes (Yahoo Finance, Claude API, Discord)
- Key conventions specific to this project
