# Weekly Report Redesign — Spec

**Date:** 2026-05-11
**Status:** Approved

## Goal

Replace the flat single-embed `PostWeeklyReportJob` output with a structured, competitive Discord report: a ranked leaderboard header followed by one rich persona card per active persona. Add a `persona_portfolio_snapshots` table to enable week-over-week portfolio value comparison.

---

## Database

### New table: `persona_portfolio_snapshots`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `persona_id` | FK → `personas` | |
| `total_value` | decimal(15,2) | cash + sum(shares × latest price) at snapshot time |
| `cash_balance` | decimal(15,2) | snapshot of cash_balance at report time |
| `snapshotted_at` | timestamp | when the snapshot was captured |
| `created_at` / `updated_at` | timestamps | |

One row per persona is inserted after each successful report post. The previous snapshot for each persona is the most recent row by `snapshotted_at` prior to the current run.

---

## New Model: `PersonaPortfolioSnapshot`

- `belongsTo(Persona)`
- Fillable: `persona_id`, `total_value`, `cash_balance`, `snapshotted_at`
- Casts: `total_value` and `cash_balance` as `decimal:2`, `snapshotted_at` as `datetime`
- Factory included

---

## Discord Message Structure

One message, 9 embeds, posted in a single `postMessage` call.

### Embed 1 — Header / Leaderboard

- **Color:** gold (`#FFD700` = `16766720`)
- **Title:** `📊 Weekly Trading Report`
- **Description:** period line + blank line + `🏆 LEADERBOARD` heading + ranked table rows + blank line + best/worst callout lines
- **Timestamp:** report period end

Leaderboard row format (monospace via code block is not used — plain text with spacing):
```
🥇 Tech Sprinter          $10,842   +$312  (+3.1%)
🥈 Snap Back              $10,650   +$195  (+1.9%)
🥉 Patient Contrarian     $10,441    +$91  (+0.9%)
4️⃣ Index Oscillator      $10,202    +$45  (+0.4%)
...
```

Rank medals: `🥇` `🥈` `🥉` for ranks 1–3; `4️⃣` through `8️⃣` for ranks 4–8.

Best/worst callout lines:
```
📈 Best this week: Tech Sprinter  (+3.1%)
📉 Worst this week: High Conviction Bull  (-7.0%)
```

When no prior snapshots exist (first ever run), WoW columns are omitted from the leaderboard rows and the callout lines are omitted entirely.

### Embeds 2–9 — Persona Cards (one per persona, in rank order)

- **Title:** rank medal + persona name (e.g. `🥇 Tech Sprinter`)
- **Description:** strategy type label — `📈 Momentum` or `🔄 Mean Reversion`
- **Color:**
  - WoW change > 0: green (`#57F287` = `5746727`)
  - WoW change < 0: red (`#ED4245` = `15548997`)
  - WoW change = 0 or no prior snapshot: neutral blue (`#3498DB` = `3447003`)

#### Inline fields (3 columns, side-by-side)

| Field name | Value example |
|---|---|
| `Total Value` | `$10,842.00` |
| `Week Change` | `+$312.00 (+3.1%)` or `—` if no prior snapshot |
| `Cash` | `$8,000.00` |

#### Non-inline field: `Open Positions`

One bullet per open position:
```
• AAPL  5 × $182.00 = $910.00    (+$210.00) 🟢
• NVDA  2 × $850.00 = $1,700.00  (+$120.00) 🟢
• META  1 × $505.00 = $505.00     (-$30.00) 🔴
```

- Unrealised P&L = `(current_price − average_cost) × shares`
- Green dot 🟢 if P&L ≥ 0, red dot 🔴 if negative
- If no price snapshot available: `• AAPL  5 shares (no price data)`
- If no open positions: `No open positions`

#### Non-inline field: `Weekly Trades`

Format: `5  (3 buys · 2 sells)`
If no trades: `No trades this week`

---

## Job Flow (`PostWeeklyReportJob::handle`)

1. Set `$periodEnd = now()`, `$periodStart = now()->subWeek()`
2. Load all active personas with `openPositions` eager-loaded
3. For each persona, compute `$totalValue`:
   - Sum `shares × latest PriceSnapshot price` for each open position
   - Fallback for positions with no snapshot: use `shares × average_cost` (conservative — avoids silently zeroing out the position)
   - Add `cash_balance`
4. Load previous snapshots: one query for the most recent `PersonaPortfolioSnapshot` per active persona
5. Sort personas descending by `$totalValue` → ranked collection
6. Build header embed (leaderboard description, best/worst callout)
7. Build one persona embed per ranked persona
8. Call `$discord->postMessage($embeds)` — throws on failure
9. Bulk-insert one `PersonaPortfolioSnapshot` row per persona (`snapshotted_at = now()`)
10. Insert `DiscordReport` record (existing behaviour)

Steps 9 and 10 only execute if step 8 succeeds (exception propagates otherwise).

---

## `DiscordService`

No changes. The existing `postMessage(array $embeds): void` signature accepts all 9 embeds.

---

## Tests

Existing tests updated to match new embed structure. New test cases:

- Header embed contains ranked persona names in correct order
- Header embed description includes best/worst callout when prior snapshots exist
- Header embed omits WoW columns and callout on first run (no prior snapshots)
- Each persona embed has correct color (green / red / neutral)
- `PersonaPortfolioSnapshot` rows are created after a successful post
- Snapshots are NOT created if Discord post fails
- WoW values show `—` when no prior snapshot exists
- Position with no PriceSnapshot falls back to `average_cost` in total value and shows `(no price data)` in embed

---

## Files Affected

| Action | File |
|---|---|
| Create migration | `database/migrations/..._create_persona_portfolio_snapshots_table.php` |
| Create model | `app/Models/PersonaPortfolioSnapshot.php` |
| Create factory | `database/factories/PersonaPortfolioSnapshotFactory.php` |
| Rewrite job | `app/Jobs/PostWeeklyReportJob.php` |
| Update tests | `tests/Feature/PostWeeklyReportJobTest.php` |
| Add relationship | `app/Models/Persona.php` — `portfolioSnapshots()` HasMany |
