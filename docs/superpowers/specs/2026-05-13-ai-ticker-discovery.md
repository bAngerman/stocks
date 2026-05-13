# AI Ticker Discovery & Assignment

**Date:** 2026-05-13
**Status:** Approved

## Goal

Replace the static `FINNHUB_WATCHLIST` config with a fully automatic, AI-driven ticker discovery pipeline. Claude suggests candidate tickers, then assigns them to personas based on each persona's strategy. No watchlist is maintained by hand.

## What Changes

| Component | Before | After |
|---|---|---|
| `MarketDataService` | Has `$watchlist` + `getGainers()` | Quotes only — `getQuote()` / `getQuotes()` |
| `SyncGainersJob` | Calls `getGainers()`, assigns all gainers to all personas | Calls `TickerDiscoveryService`, assigns by persona fit |
| `TickerDiscoveryService` | Does not exist | New service — two Claude API calls |
| `config/services.php` | `finnhub.watchlist` key | Removed |
| `TickerSource::GainersScan` | Used | Replaced by `TickerSource::AiDiscovered` |

`persona_tickers` table is unchanged. Pruning of stale candidates is added.

## TickerDiscoveryService

New service at `app/Services/TickerDiscoveryService.php`. Follows the same structure as `AIEvaluator` — direct HTTP to the Anthropic API, JSON parsing with markdown fence stripping.

### Phase 1 — `discoverPool(int $count = 25): array`

Single Claude call. Prompt asks for `$count` US stock/ETF tickers worth watching for active intraday trading. Response is a JSON array:

```json
[
  {"ticker": "NVDA", "name": "NVIDIA Corp", "rationale": "High momentum, AI sector tailwind"},
  ...
]
```

Returns `array<int, array{ticker: string, name: string, rationale: string}>`. Returns empty array on parse failure (logged as warning).

### Phase 2 — `assignToPersonas(array $pool, Collection $personas): array`

Single Claude call. Prompt includes the full pool and each persona's name, strategy type, and description. Claude is instructed to only assign tickers that appear in the pool. Response:

```json
{
  "Momentum Mike": ["NVDA", "TSLA"],
  "Mean Reversion Sally": ["AAPL", "SPY"]
}
```

Returns `array<string, string[]>` (persona name → ticker symbols). Returns empty array on parse failure (logged as warning).

## SyncGainersJob Flow

```
1. Prune stale candidates
   → DELETE persona_tickers WHERE status = 'candidate' AND created_at < now() - candidate_ttl_days

2. Load active personas

3. discoverPool() → $pool
   → if empty, log warning and return early

4. assignToPersonas($pool, $personas) → $assignments
   → if empty, log warning and return early

5. Validate tickers
   → getQuote() each unique assigned ticker against Finnhub
   → drop any ticker with zero price or that throws

6. Persist
   → For each persona, firstOrCreate each valid assigned ticker
      with status = Candidate, source = AiDiscovered, ai_rationale from pool
```

## Ticker Validation

After assignment, each unique ticker gets a `getQuote()` check against Finnhub before being persisted. Any ticker returning zero price or throwing an exception is dropped. This guards against hallucinated symbols without adding expensive validation logic downstream.

## Configuration

Remove from `config/services.php`:
```php
'watchlist' => explode(',', env('FINNHUB_WATCHLIST', '...')),
```

Add to `config/trading.php` (new file, or existing if present):
```php
'candidate_ttl_days' => env('CANDIDATE_TTL_DAYS', 7),
```

Remove `FINNHUB_WATCHLIST` from `.env` and `.env.example`. Add `CANDIDATE_TTL_DAYS=7`.

## Error Handling

- `discoverPool()` parse failure → log warning, return `[]`, job returns early
- `assignToPersonas()` parse failure → log warning, return `[]`, job returns early
- Individual ticker validation failure → log warning for that ticker, skip it, continue
- Anthropic HTTP error → exception propagates to job's `failed()` handler (existing pattern)

## Testing

- `TickerDiscoveryServiceTest` — unit tests with `Http::fake()`:
  - `discoverPool()` returns parsed array for valid response
  - `discoverPool()` returns `[]` and logs warning for unparseable response
  - `discoverPool()` strips markdown fences before parsing
  - `assignToPersonas()` returns correct persona→ticker map
  - `assignToPersonas()` returns `[]` and logs warning for unparseable response
- `SyncGainersJobTest` — feature test:
  - Prunes candidates older than TTL
  - Does not prune active tickers
  - Persists assigned tickers with correct source and rationale
  - Returns early when pool is empty
  - Drops tickers that fail Finnhub validation
- `MarketDataServiceTest` — remove any tests for `getGainers()`
