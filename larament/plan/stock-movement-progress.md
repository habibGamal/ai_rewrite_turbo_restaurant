# Stock Movement Fixes — Progress

Plan: [stock-movement-fixes.md](stock-movement-fixes.md)
Implementer: Antigravity `agy` 1.4.10, model `gemini-3.8-flash-high`, `--dangerously-skip-permissions`.
Landing: nothing committed (user's choice). Changes stay in the working tree alongside existing uncommitted work.

## Status

| Phase | Scope | Status | Notes |
|---|---|---|---|
| 1 | StockService correctness (atomic updates, merge duplicates, throw on failure, day guard, unique inventory row) | reviewed (uncommitted) | 1 rework round (tests vs unique index) + 1 fix by Claude (rollback scope) |
| 2 | Block cancel/return of completed orders while day closed | reviewed (uncommitted) | 1 network-error resume; Claude added `openInventoryDay()` to the old "admin cancels completed order" test |
| 6 | 3-decimal quantity precision | reviewed (uncommitted) | Claude switched 3 inventory display columns to 3 decimals |
| 3 | Daily aggregation hardening (`closeDay` re-aggregates, `currentBusinessDate()`) | reviewed (uncommitted) | 1 network-error resume |
| 4+5 | Stock report query (dates, start/actual) + UI/exporter cleanup | reviewed (uncommitted) | agy hit its quota during final gates; Claude ran the gates and removed `->sortable()` from a computed column |
| 7 | Stocktaking close warning | reviewed (uncommitted) | Claude removed a `@phpstan-ignore` agy added inside the untouched `closeStocktaking()` |

## Review notes

**Baseline (before any change):** `php -d memory_limit=1G vendor/bin/pest` → 109 failed, 44 passed. These failures already existed and are unrelated: stale factories reference the removed `products.printer_id`, Filament test selectors are outdated, auth routes are missing. `--parallel` crashes (paratest + PHP 8.5), and the default 128 MB memory limit is too small. Gate rule for every phase: **no new failures**.

**Phase 1 review:**
- The diff matches the brief: `normalizeItems()` merges duplicates and rounds to 3 decimals; inventory reads use `lockForUpdate`; updates use atomic `increment`/`decrement`; an outgoing movement on a missing row now inserts −q; failures throw `StockOperationException`; and `shouldDayBeOpen()` guards every stock movement.
- The migration merges duplicate rows, backfills missing ones and adds the unique index. `ProductObserver` now creates an inventory row when a product's type changes away from Manufactured.
- Claude fixed one thing directly: the catch block rolled back whenever `DB::transactionLevel() > 0`, so a "no open day" error would have rolled back the caller's transaction. It now uses a `$transactionStarted` flag.
- Pint passes. PHPStan's 12 errors in these files all come from older code; none are in new lines.
- agy claimed "zero new failures", but the full run showed 37 new ones. 36 were tests creating a second inventory row, which the new unique index now rejects; the other was a possibly flaky WebOrdersReport search. A delta brief to fix the tests was sent.
- After the rework, agy switched the test setup to `InventoryItem::updateOrCreate`. Claude's own rerun: no new failures except `WebOrdersReportTest > search orders by id`, which also fails on its own. That failure is unrelated to the stock work: the search also matches the shared customer's random phone number, and no file in its path changed. ✅ Phase 1 accepted.

**Phase 2 review:**
- `cancelOrder()` calls `shouldDayBeOpen()` only for COMPLETED orders, before any writes; rejected or pending web orders can still be cancelled while the day is closed.
- `processReturn()` checks for an open day first.
- `openDay()` logs a warning when live stock ≠ the last `end_quantity`.
- 6 new tests in `tests/Feature/Orders/ClosedDayOrderCancellationAndReturnTest.php`.
- Full suite: no new failures. The only extra one, `SupplierResourceTest > create`, is a random phone clash and passes on its own. ✅ Accepted.

**Phase 6 review:**
- Migration `2026_10_29_000002_increase_stock_quantity_precision.php` moves every stock quantity column to `decimal(12,3)` (defaults kept). Its `down()` restores the original sizes.
- Casts are now `decimal:3`, and the purchase/return/waste/stocktaking quantity inputs use `->step(0.001)`.
- A new test checks that 0.015 is stored exactly (10 → 9.985).
- Claude also switched the inventory movements, recipe and total-consumed display columns from 2 to 3 decimals (cost stays at 2).
- Full suite: no new failures except known flaky tests. ✅ Accepted.
- ⚠️ Before running this on production MySQL: take a backup. `->change()` rebuilds the tables.

**Phase 3 review:**
- `closeDay()` now re-aggregates every open product before snapshotting `end_quantity`. Its later `update()` writes only `end_quantity`/`closed_at`, so it doesn't overwrite the fresh totals.
- New `currentBusinessDate()` returns the open day's date, or today when no day is open.
- The unused `$date` parameter is documented.
- New tests cover the stale-row re-aggregation and the business date. Full suite: no new failures except the flaky supplier test. ✅ Accepted.

**Phases 4+5 review:**
- New `App\Services\Reports\StockReportService` with three parts:
  - `resolveDates()` caps the end date at the business date and switches to live stock when the range reaches it.
  - `query()` uses bound parameters and `DATE()` comparisons. Start = the first in-range row's `start_quantity` → the previous end → 0. Actual = live stock, or the latest closed row at or before the end date.
  - `calculate()` holds the shared rounded math. Deviation value is signed; the percentage is null when ideal is 0.
- Widget and exporter both use the service.
- UI fixes:
  - Filters are grouped correctly, and "has movements" now checks for real activity.
  - The view action is hidden when there's no inventory row.
  - Cost is labelled "التكلفة الحالية", and the deviation column has a tooltip saying it includes stocktaking adjustments.
  - The route path is now `stock-report`, and the dead `return_sales` export column is gone.
- agy also added "الكمية الكلية المنصرفة" and "الكمية المتبقية المثالية" columns to the on-screen table (they were already in the export, and old tests expected them).
- Claude removed `->sortable()` from the computed ideal column, because sorting by it would throw an SQL error.
- 6 new tests in `tests/Feature/Reports/StockReportServiceTest.php` replay the review scenarios: 1 am with the day still open, "this month", a holiday end date, the first period, a stocktaking difference, ideal = 0.
- Full suite: no new failures except the flaky driver-phone test. ✅ Accepted.

**Phase 7 review:**
- `StocktakingService::getChangedItemsSinceCount()` compares each line's saved `stock_quantity` with live stock.
- The close modal (form and table actions) adds an Arabic warning when stock changed since counting (up to 5 examples, names escaped) or when a shift is open. The close logic itself is unchanged.
- 9 new tests in `tests/Unit/Services/StocktakingServiceTest.php`. ✅ Accepted.

## Final result

- Full suite vs the **original** baseline (109 failures): 110 failed.
  - Fixed: `StockReportTest > filter products without stock`.
  - New: `WebOrdersReportTest > search orders by id` (unrelated, data-dependent, see below) and `DriverResourceTest > create a driver` (random phone clash, passes alone).
- No ignored stock results remain; `StockService` never returns false.
- Only one `decimal:2` quantity cast remains, on order-return items, which are whole units.

## Needs your eyes (Phase 1)

- `WebOrdersReportTest > it can search orders by id` fails consistently now but passed in the baseline. It doesn't touch stock code; the test looks data-dependent (the search covers `customer.phone`, and both orders share one customer). Worth a look on its own.
- Behaviour change: any stock movement now **requires an open day** and **throws** on failure. A failed deduction stops an order from completing, and the cashier sees `فشل في تحديث المخزون: …`.

## Needs your eyes

_(design decisions made by the implementer, open questions)_

## End-of-run checklist

- [x] Tests: no new stock-related failures (composer review not used: `--parallel` crashes here; ran pest serially + pint + phpstan per phase)
- [x] No ignored `addStock`/`removeStock` results left
- [x] No `decimal:2` stock quantity casts left
- [x] Only planned files changed beyond pre-existing edits
- [ ] Back up DB before running the new migrations in production
