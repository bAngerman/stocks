# Polygon.io Market Data Migration

**Date:** 2026-05-12  
**Status:** Approved

## Problem

`scheb/yahoo-finance-api` is hitting 429 Too Many Requests errors from Yahoo Finance. The library fetches a crumb token before each request, which Yahoo Finance rate-limits aggressively.

## Solution

Replace Yahoo Finance with [Polygon.io](https://polygon.io) (now rebranded as Massive). Polygon's free tier provides unlimited API calls with 15-minute delayed data — sufficient for this paper trading bot.

## Constraints

- Free tier only (no paid plan)
- 15-minute delay is acceptable
- Both quote fetching and gainers list must be replaced

## Scope

Changes are fully contained within `MarketDataService` and its tests. No callers (`EvaluatePersonaJob`, `SyncGainersJob`) require modification.

## API Mapping

**Base URL:** `https://api.polygon.io`  
**Auth:** `Authorization: Bearer {POLYGON_API_KEY}` header

### Quote endpoint

```
GET /v2/snapshot/locale/us/markets/stocks/tickers/{ticker}
```

Response fields used:
- `ticker.lastTrade.p` → `MarketQuote::price`
- `ticker.todaysChangePerc` → `MarketQuote::changePercent`

### Gainers endpoint

```
GET /v2/snapshot/locale/us/markets/stocks/gainers
```

Returns top 20 US equity gainers. Response is an array of the same ticker snapshot structure. Fields used:
- `ticker` → `ticker`
- `todaysChangePerc` → `changePercent`
- `ticker` (again, as fallback) → `name` (not consumed by any caller)

No type filtering needed — the gainers endpoint exclusively returns US equities.

## File Changes

### Remove

- `scheb/yahoo-finance-api` from `composer.json`

### Modify

| File | Change |
|------|--------|
| `app/Services/MarketDataService.php` | Replace `ApiClient` dependency with `Http` facade calls to Polygon |
| `app/Providers/AppServiceProvider.php` | Remove `ApiClient` singleton binding; simplify `MarketDataService` binding |
| `config/services.php` | Add `'polygon' => ['key' => env('POLYGON_API_KEY')]` |
| `.env` | Add `POLYGON_API_KEY=` |
| `.env.example` | Add `POLYGON_API_KEY=` |
| `tests/Feature/MarketDataServiceTest.php` | Rewrite to mock Polygon quote endpoint via `Http::fake()` |
| `tests/Feature/Services/MarketDataServiceTest.php` | Rewrite to mock Polygon gainers endpoint via `Http::fake()` |

## Error Handling

- `getQuote()`: throws on HTTP failure (callers catch via `EvaluatePersonaJob` try/catch — no change)
- `getGainers()`: returns empty collection on any failure (no change)

## Test Strategy

Both `MarketDataServiceTest` files are rewritten against Polygon's JSON response shape. All other tests (`SyncGainersJobTest`, `EvaluatePersonaJobTest`) mock `MarketDataService` directly and require no changes.

**Gainers test adjustments:**
- "filters out non-equity instruments" test is dropped (Polygon gainers endpoint only returns equities by definition)
- Replaced with a test confirming an empty-array response is handled gracefully
