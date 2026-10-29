# Stock Movement & Stock Report Fixes

> First implementation step: copy this plan to `larament/plan/stock-movement-fixes.md` (the user asked for it to be saved in `./plan`).

## Context

A review of the stock movement flow (`StockService` → `inventory_item_movements` → `InventoryDailyAggregationService` → `inventory_item_movement_daily`) and of the Stock Report (`StockReportTable`) found that stock can be silently lost and that the report shows wrong deviations in common situations: after midnight, future end dates, concurrent orders, failed waste/stocktaking closes, closed-day returns, and rounding. The goal is for inventory to always match the movement log, and for the report's "deviation" to show only real, unexplained differences.

Decisions made with the user:
- Order cancel/return of a **completed** order is **blocked while the day is closed**.
- Stocktaking keeps its current math (variance vs stock at close time), but **shows a warning** before closing.
- Stocktaking/manual adjustments **stay in "deviation"** (no new column).
- Quantities move to **3 decimals**.
- If stock deduction fails while completing an order, the **order completion fails** (rolls back with an error).

---

## Phase 1: StockService correctness (issues 1, 2, 5, low "no inventory row")

File: `app/Services/StockService.php`

1. **Merge duplicates first.** At the start of `processStock()`, group `$items` by `product_id` and sum quantities (same logic as `OrderStockConversionService::optimizeStockItems()`; extract it to a small shared helper or a private method). Round each quantity with `round($q, 3)`.
2. **Atomic updates, no lost writes.**
   - Replace `updateExistingInventory()`'s read-then-write with `InventoryItem::where('product_id', $id)->increment('quantity', $q)` / `->decrement(...)`.
   - `getExistingInventory()` reads with `->lockForUpdate()` (needed only to decide create vs update).
3. **Products without an inventory row.**
   - Incoming: create the row (current behaviour).
   - Outgoing: create the row with quantity `-q` (negative stock is allowed by `ALLOW_INSUFFICIENT_STOCK`), so the deduction is no longer dropped.
4. **Fail loudly.** In `processStock()`, after `DB::rollBack()` and logging, **rethrow** (wrap it in a new `App\Exceptions\StockOperationException`) instead of returning `false`. Keep the `bool` return type, returning `true` on success, so callers' signatures don't change.
5. **Central day-open guard.** Call `shouldDayBeOpen()` (`app/Helpers.php`) at the start of `processStock()`, before the transaction. Every stock movement then needs an open day.
6. Keep `updateDailyAggregations()` swallowing its own errors: the daily row is recalculated from the movement log on the next movement and at close (see Phase 3), so it heals itself.

Callers to adapt:
- `app/Services/WasteService.php` and `app/Services/StocktakingService.php`: no logic change needed. The exception now propagates into their existing `catch`, which rolls back and shows the error. Remove the "Removed stock…" log that assumed success.
- `app/Services/PurchaseService.php`: the `if (! $success)` checks become dead code. Leave them or simplify them; behaviour is unchanged.
- `app/Services/Orders/OrderStockConversionService.php`: `removeStockForCompletedOrder()` / `addStockForCancelledOrder()` stop catching and returning `false`, and let the exception propagate.
- `app/Services/Orders/OrderService.php` (`completeOrder` ~L202, `cancelOrder` ~L245): drop the "log warning and continue" branches. The exception rolls back the surrounding `DB::transaction`, so the order is not completed or cancelled, and the cashier sees the error.

DB constraint (new migration, e.g. `2026_10_29_000001_add_unique_product_to_inventory_items.php`):
- Merge any existing duplicate `inventory_items` rows per product: keep the lowest id and sum their quantities.
- Backfill missing rows (quantity 0) for non-manufactured products. This covers products created via `Product::insert` in `MigrateOldDataCommand`.
- Add `unique('product_id')`.

Also: `app/Observers/ProductObserver.php`: in `saved()`/`updated()`, if the type changed **from** Manufactured to Consumable/RawMaterial and no inventory row exists, create one with quantity 0.

## Phase 2: Block closed-day returns/cancels (issue 6)

- `app/Services/Orders/OrderService.php::cancelOrder()`: right after loading the order, if `$order->status === OrderStatus::COMPLETED`, call `shouldDayBeOpen()` **before** freeing the table or deleting payments. Non-completed cancels, such as rejected web orders, stay allowed because they don't touch stock.
- `app/Services/Orders/OrderReturnService.php::processReturn()`: call `shouldDayBeOpen()` at the top.
- The Phase 1 guard inside `StockService` is the safety net for any other path.
- `InventoryDailyAggregationService::openDay()`: keep `start = last end_quantity`. If it differs from live `inventory_items.quantity`, log a warning with both numbers, so any leftover gap is visible instead of silent.

## Phase 3: Daily aggregation hardening

File: `app/Services/InventoryDailyAggregationService.php`

- `closeDay()`: before writing `end_quantity`, call `bulkAggregateWithInsertSelect()` for **all** product ids with open rows, so totals are fresh even if an earlier aggregation call failed.
- Add `currentBusinessDate(): Carbon`, which returns `dayStatus() ?? Carbon::today()`. The report uses it (Phase 4).
- Remove the unused `$date` parameter path in `aggregateMultipleMovements()` (keep the signature; ignore it). Optional cleanup.

## Phase 4: Stock report logic (issues 3, 4, 9)

Move the report query out of the widget into a reusable class, `app/Filament/Widgets/Support/StockReportQuery.php` (or `app/Services/Reports/StockReportQuery.php`), with `build(string $startDate, string $endDate): Builder`. Then `StockReportTable` and the exporter share it and tests can hit it directly.

Date resolution (inside the query class):
- `$business = currentBusinessDate()`, i.e. the open day's date, or today when no day is open.
- Clamp `$endDate = min($endDate, $business)` and `$startDate = min($startDate, $business)`. This fixes "this week/month/year" (future end date) and "today" at 1 am, which now shows the open business day.
- `$useLive = ($endDate >= $business)`.

Columns:
- **actual_remaining**: if `$useLive`, use `inventory_items.quantity`. Otherwise take `end_quantity` of the **latest row with `date <= $endDate`**, not "exactly `$endDate`". This fixes holidays and days with no row, and since that row is in the past it is always closed.
- **start_quantity**: `start_quantity` of the **first row in the range** (`MIN(date)` between start and end). If there is none, fall back to `end_quantity` of the last row before `$startDate`. If there is none, 0. This fixes the first period and new products.
- incoming / sales (net of returns) / return_waste: unchanged sums over rows in range.
- Use bound parameters instead of string-interpolated dates in the raw subqueries.

## Phase 5: Report UI and exporter cleanup (low-severity items)

`app/Filament/Widgets/StockReportTable.php`:
- **One calculation helper.** Make a private static `calc($record): array` (total, consumed, ideal, deviation) using `round(..., 3)`. Every column and color closure uses it, which removes the four copies of the formula and the float `==` problem.
- **deviation_value**: signed, not `abs()`. Color danger if < 0, warning if > 0, success if 0.
- **deviation_percentage**: divide by `abs($ideal)`. Return null and show "—" when ideal is 0.
- **has_movements filter**: base it on real movements. Use `whereExists` on `inventory_item_movements` with `created_at` within the range dates, or sum the daily columns > 0. Do not rely on row existence.
- **has_stock = false** and **zero_cost** filters: wrap the OR in a nested `where(fn ($q) => ...)`.
- **view_product action**: `->visible(fn ($record) => filled($record->inventory_item_id))`.
- Rename the `cost` label to "التكلفة الحالية" (it is the current cost, not the period average).
- Deviation column `->tooltip(...)`/description: "يشمل تسويات الجرد" (keeps the decision to leave stocktaking in deviation, but makes it explicit).

`app/Filament/Exports/StockReportExporter.php`:
- Drop the `return_sales` column and its use in totals.
- Reuse the same calc helper (make it a public static on a small `StockReportCalculator` class in the same Support folder, used by both).

`app/Filament/Pages/Reports/StockReport.php`: `$routePath = 'stock-report'`.

## Phase 6: 3-decimal precision (issue 7)

New migration `2026_10_29_000002_increase_stock_quantity_precision.php` (uses `->change()`):
- `inventory_items.quantity` → `decimal(12,3)`
- `inventory_item_movements.quantity` → `decimal(12,3)`
- `inventory_item_movement_daily`: all six quantity columns → `decimal(12,3)`
- `purchase_invoice_items.quantity`, `return_purchase_invoice_items.quantity`, `waste_items.quantity`, `stocktaking_items.stock_quantity` and `real_quantity` → `decimal(12,3)`

Update the model casts from `decimal:2` to `decimal:3` for quantity fields in `InventoryItemMovement`, `InventoryItemMovementDaily`, `InventoryItem` and the item models (`PurchaseInvoiceItem`, `ReturnPurchaseInvoiceItem`, `WasteItem`, `StocktakingItem`; check each for casts). Quantity `TextInput`s in the Purchase/Waste/Stocktaking resources get `->step(0.001)` where a step is set. Money columns stay at 2 decimals.

## Phase 7: Stocktaking warning (issue 10, the user's choice)

`app/Filament/Actions/CloseStocktakingAction.php` (both `make()` and `table()`):
- Make `modalDescription` a closure that compares each item's `stock_quantity` (the snapshot stored when the line was added) with current `inventory_items.quantity`.
- If any differ, or `ShiftService::getCurrentShift()` is not null, append a warning in Arabic: "تنبيه: تغيّر رصيد N صنف منذ إدخال الجرد (مثال: …). سيتم حساب الفرق مقابل الرصيد الحالي وقت الإغلاق، وأنت مسؤول عن صحة الكميات."
- List up to 5 product names with snapshot vs current. Logic in `StocktakingService::closeStocktaking()` stays unchanged.

---

## Verification

New/updated Pest tests (SQLite, existing patterns in `tests/Unit/Services/StockServiceTest.php` and `tests/Feature/Filament/Pages/Reports/StockReportTest.php`; daily rows via `InventoryItemMovementDailyFactory`):

- `StockServiceTest`
  - Duplicate product lines are merged (10 − 2 − 3 = 5).
  - Outgoing on a product with no inventory row creates −q.
  - A failure throws `StockOperationException` and leaves no partial writes.
  - Throws when no day is open.
  - Stores 3 decimals (0.015 stays 0.015).
- Order tests (`tests/Feature/Orders/`)
  - Cancelling a completed order with the day closed throws, and the payments and table are untouched.
  - A return with the day closed throws.
  - Completion rolls back when stock fails.
- `WasteService` / `StocktakingService`: a stock failure keeps the record open.
- `InventoryDailyAggregationServiceTest`: `closeDay` re-aggregates before snapshotting; `currentBusinessDate()`.
- New `StockReportQueryTest`, checking values (not only columns):
  - The day opened yesterday and is still open: "today" shows yesterday's sales, live actual, deviation 0.
  - A "this month" range: actual = live, not 0.
  - An end date on a day with no row uses the last earlier closed row.
  - First period: start = the first row's `start_quantity`.
  - A stocktaking adjustment shows as deviation.
  - The filters combine correctly with a category.
- Precision: a migration test, or an assertion that a 0.015 movement round-trips.

Commands:
- `cd D:\ai_rewrite_turbo_restaurant\larament; ./vendor/bin/pest --parallel`
- `composer review` (Pint → Pest → PHPStan)
- Manual check by the user in the running app (don't run `php artisan serve`): open a day, complete orders, then open the Stock Report with "اليوم", "هذا الشهر" and "أمس". Deviation should be 0 when nothing is missing. Try closing a stocktaking after selling a counted item to see the warning.

Before deploying:
- Back up the DB. The precision and unique-index migrations alter live tables, and the unique migration merges duplicate inventory rows.
- Run `php artisan inventory:fix-daily-aggregation --all --dry-run` to review historical daily rows.

## Suggested order

Phase 1 → 2 → 6 (data correctness, migrations together) → 3 → 4 → 5 → 7. Each phase ends with its tests passing.

---

## Execution: delegated to Antigravity (`agy`)

The phases are implemented by the Antigravity CLI using the `agy-delegate` skill. Claude orchestrates: it writes each brief, dispatches the run, reviews the result and runs the gates.

- **Model:** `gemini-3.8-flash-high` (`--model gemini-3.8-flash-high --effort high`).
- **Permissions:** the user granted full permission, so each dispatch passes `--dangerously-skip-permissions`. agy 1.4.10 has no allow-rules for this repo.
- **Landing:** **nothing is committed.** The working tree already holds the user's uncommitted roles/permissions work on `master`; delegated changes stay uncommitted next to it. Briefs tell agy not to run `git add`, `git commit`, `git stash`, `git checkout` or `git reset`, and not to touch unrelated modified files.
- **Step 0 (Claude):** create `larament/plan/` and copy this plan there as `stock-movement-fixes.md`. Also create `larament/plan/stock-movement-progress.md` as the progress file: a status table per phase, review notes, a "needs your eyes" list, and an end-of-run checklist.

**Queue:** 7 sequential briefs, one per phase, in the order 1 → 2 → 6 → 3 → 4 → 5 → 7. The next brief is dispatched only after the previous one passes review.

For each phase:
1. Snapshot the working tree (`git status --porcelain` plus file hashes into the scratchpad), so this phase's edits can be told apart from the user's existing changes.
2. Write the brief in the skill's XML shape (`<task>`, `<verification_loop>`, `<action_safety>`, `<structured_output_contract>`).
   - Include the phase's section from this plan, the exact files, and the user decisions above.
   - Restate facts from earlier phases, such as the `StockOperationException` name, the `currentBusinessDate()` helper and the calculator class name.
   - Repeat the project rules: the Arabic UI, never run `php artisan serve`, PHPStan level 5.
3. Dispatch in the background: `node "C:\Users\habib\.claude\skills\agy-delegate\scripts\relay.mjs" --brief <scratchpad>\phaseN.txt --cd D:\ai_rewrite_turbo_restaurant\larament --model gemini-3.8-flash-high --effort high --dangerously-skip-permissions --timeout 45m --stall-timeout 10m`.
4. Review without trusting agy's report:
   - Read `result.json` and diff against the snapshot.
   - Check the diff against the brief: nothing missing, nothing extra.
   - Re-run the gates myself: `./vendor/bin/pint --test` on the changed files, `./vendor/bin/pest --parallel`, `./vendor/bin/phpstan analyse`.
   - For Phases 1 and 6, run `php artisan migrate --pretend` on the SQLite test setup through the test suite.
5. If something is wrong, send a delta brief with `--resume-last`, then review again. Update the progress file.
6. Stop and ask the user if a phase can't be done within the plan, or if a review calls the plan itself into question.

**End-of-run coherence check:**
- Full `composer review`.
- `grep` for leftover `return false` / ignored `removeStock`/`addStock` results and for any remaining `decimal:2` quantity casts.
- Confirm that only the planned files changed beyond the user's pre-existing edits.
- Summarize everything for the user. Nothing is committed.
