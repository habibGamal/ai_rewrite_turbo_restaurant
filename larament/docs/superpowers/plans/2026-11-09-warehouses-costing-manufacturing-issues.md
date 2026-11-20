# Warehouses, Costing, Manufacturing, Category Routing & Stock Issues — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a restaurant keep stock in several warehouses, move it between them, make semi-finished items in batches, issue items at cost, value everything with a chosen costing method, and route POS sales to the right warehouse by category.

**Architecture:** Everything sits on one **costed ledger**: every stock change goes through `StockService`, carries a `warehouse_id`, a `unit_cost`/`total_cost` and a `user_id`, and asks a `CostingStrategy` (Off / Immediate / Weighted Average — Strategy pattern; FIFO postponed, spec Appendix A) for its cost. New documents (transfer, manufacturing order, stock issue) are thin services on top of `StockService`. Reports read the same ledger, so their quantities always agree.

**Tech Stack:** Laravel 11, FilamentPHP v4, Pest (SQLite in-memory), Spatie Permission, React + Inertia (POS).

**Spec:** `docs/superpowers/specs/2026-11-09-warehouses-costing-manufacturing-issues-design.md` — read it first; every worked example below comes from it.

## Global Constraints

- Arabic UI labels (add to `lang/ar.json`), currency EGP, timezone `Africa/Cairo`.
- Run commands from `larament/`. Never run `php artisan serve`.
- Quality gate after every Part: `composer review` (Pint → Pest → PHPStan level 5) must be green.
- Stock quantities: `decimal(12,3)`. Unit cost: `decimal(12,4)`. Money totals: `decimal(14,2)`.
- Negative stock stays allowed (`StockService::ALLOW_INSUFFICIENT_STOCK = true`), per warehouse.
- One cost per product across all warehouses. Transfers never change cost. FIFO is out of scope (spec Appendix A) — do not build it, but keep the interface able to take it later.
- Every new stock document follows the existing `closed_at` pattern (Draft = `closed_at` null, Done = set) and records `business_date` via `InventoryDailyAggregationService::currentBusinessDate()`.
- Every stock change calls `shouldDayBeOpen()` (as `StockService` already does).
- New permissions go in `database/seeders/RolesAndPermissionsSeeder::permissionsCatalog()` (deploy runs the seeder). Policies extend `App\Policies\BasePolicy` with `$resource`.
- Day-one parity: a single-warehouse restaurant must see identical stock report numbers before and after the upgrade.
- Commit after each task (message style: `feat(inventory): …`), ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Same product twice in one document** (two lines of 2 kg + 3 kg rice) — expected: one 5 kg movement per (product, warehouse), costed once. Test in Task A3.
2. **Free items on a purchase invoice** (10 kg @ 20 on hand, invoice adds 10 kg @ 0 as a free sample) — expected under average: allowed, cost becomes 10, history row written. Test in Task B2.
3. **Manufacturing with awkward decimals** (pot 100.00 split 33.333% / rest) — expected: sum of allocated shares equals the pot exactly; main takes the remainder. Test in Task E3.
4. **Routing to an inactive warehouse** (category points to a warehouse later deactivated) — expected: fall back to the default warehouse, never fail the sale. Test in Task D2.
5. **Transfer more than the source holds** (Prep has 3 kg, transfer 5 kg) — expected: allowed, Prep −2, destination +5, all-warehouse total unchanged. Test in Task C1.

---

## Part A — Warehouses foundation

Simple idea: today there is one shelf. After Part A there are many shelves (warehouses), and every movement says which shelf. Nothing about cost changes yet.

### Task A1: Warehouse model, table, admin screen, backfill

**Files:**
- Create: `database/migrations/2026_11_10_000001_create_warehouses_table.php`, `app/Models/Warehouse.php`, `database/factories/WarehouseFactory.php`, `app/Filament/Resources/Warehouses/WarehouseResource.php` (+ `Pages/`), `app/Policies/WarehousePolicy.php`
- Modify: `RolesAndPermissionsSeeder.php` (add `warehouses`), `app/Observers/ProductObserver.php` (new product → 0 row in default warehouse)
- Test: `tests/Feature/Inventory/WarehouseTest.php`

**Interfaces:**
- Produces: `Warehouse` (`name`, `is_default`, `is_active`); `Warehouse::default(): Warehouse` (cached per request); `Warehouse::scopeActive()`.

- [ ] **Step 1: Write failing tests**
```php
it('creates the default main warehouse on migrate', fn () =>
    expect(Warehouse::default()->name)->toBe('المخزن الرئيسي')->and(Warehouse::where('is_default', true)->count())->toBe(1));
it('refuses to delete or deactivate the default warehouse');      // throws / validation error
it('refuses to delete a warehouse that has movements');            // deactivate instead
it('setting another warehouse as default unsets the old one');
```
- [ ] **Step 2:** `./vendor/bin/pest tests/Feature/Inventory/WarehouseTest.php` → FAIL (class not found)
- [ ] **Step 3: Implement.** Migration creates table and inserts the default row. Guards live in the model `deleting`/`updating` events so both Filament and code respect them.
- [ ] **Step 4:** same command → PASS
- [ ] **Step 5: Commit** `feat(inventory): add warehouses with a default main warehouse`

### Task A2: Ledger columns and new movement reasons

**Files:**
- Create: `database/migrations/2026_11_10_000002_add_warehouse_and_cost_to_inventory_ledger.php`
- Modify: `app/Enums/MovementReason.php`, `app/Models/InventoryItem.php`, `InventoryItemMovement.php`, `InventoryItemMovementDaily.php`, their factories
- Test: `tests/Feature/Inventory/LedgerMigrationTest.php`, `tests/Unit/Enums/MovementReasonTest.php`

**Interfaces:**
- Produces: columns from spec §2 table (`inventory_items.warehouse_id` + unique `(product_id, warehouse_id)`; movements `warehouse_id, unit_cost, total_cost, user_id`, quantity `decimal(12,3)`; daily `warehouse_id` + unique `(product_id, warehouse_id, date)` + `transfer_in_quantity, transfer_out_quantity, manufacturing_in_quantity, manufacturing_out_quantity, issue_quantity`).
- Produces: `MovementReason` cases `TRANSFER_IN='transfer_in'`, `TRANSFER_OUT='transfer_out'`, `MANUFACTURING_IN='manufacturing_in'`, `MANUFACTURING_OUT='manufacturing_out'`, `STOCK_ISSUE='stock_issue'`. Keep legacy `TRANSFER`.

- [ ] **Step 1: Write failing tests**
```php
it('backfills existing stock, movements and daily rows into the default warehouse'); // all warehouse_id == default id
it('values old movements at the product cost at migration time');   // 3 kg, cost 20 → unit_cost 20, total_cost 60
it('allows the same product in two warehouses but not twice in one');
it('labels the new reasons in Arabic');  // TRANSFER_IN → 'تحويل وارد', STOCK_ISSUE → 'صرف'
```
- [ ] **Step 2:** run both files → FAIL
- [ ] **Step 3: Implement.** Migration: add nullable columns → backfill with default id and `products.cost` → make `warehouse_id` not null → swap unique indexes (drop `inventory_items` unique on `product_id` from `2026_10_29_000001`). Must work on MySQL and SQLite.
- [ ] **Step 4:** run → PASS; also run `./vendor/bin/pest tests/Unit/Services/StockServiceTest.php tests/Feature/Reports/StockReportServiceTest.php` → still PASS
- [ ] **Step 5: Commit** `feat(inventory): warehouse, cost and user columns on the stock ledger`

### Task A3: StockService becomes warehouse-aware

**Files:**
- Modify: `app/Services/StockService.php`, `app/DTOs/StockMovementDTO.php`
- Test: `tests/Unit/Services/StockServiceTest.php` (extend)

**Interfaces:**
- Produces: item line shape `['product_id' => int, 'quantity' => float, 'warehouse_id' => ?int, 'unit_cost' => ?float]`; missing `warehouse_id` → `Warehouse::default()->id`.
- Produces: `addStock(array $items, MovementReason $reason, ?Model $ref = null): Collection<InventoryItemMovement>` and `removeStock(...)` same — **return the created movements** (later tasks read their costs).
- Produces: `getCurrentStock(int $productId, ?int $warehouseId = null): float` (null = sum of all warehouses).
- Movements are created with Eloquent (not bulk `insert`) so callers get ids and costs back.

- [ ] **Step 1: Write failing tests**
```php
it('adds stock to the given warehouse only');          // Grill +10 → Grill 10, Main 0
it('defaults to the default warehouse when none is given');
it('lets one warehouse go negative');                   // Grill 0, remove 2 → Grill -2
it('merges duplicate lines per product and warehouse'); // 2 kg + 3 kg rice in Main → one movement of 5
it('keeps lines for the same product in different warehouses separate');
it('records warehouse_id and the acting user on each movement');
it('sums all warehouses when no warehouse is passed to getCurrentStock'); // Main 30 + Grill -2 → 28
```
- [ ] **Step 2:** run → FAIL
- [ ] **Step 3: Implement.** Normalize/group by `(product_id, warehouse_id)`; lock `inventory_items` by `(product_id, warehouse_id)`. Cost columns are filled with `products.cost` for now (Part B replaces this).
- [ ] **Step 4:** run the file plus `tests/Feature/Orders/OrderStockConversionFeatureTest.php` → PASS
- [ ] **Step 5: Commit** `feat(inventory): stock service works per warehouse`

### Task A4: Daily aggregation per warehouse + sync payload

**Files:**
- Modify: `app/Services/InventoryDailyAggregationService.php`, `app/Sync/Builders/InventoryDayBuilder.php`
- Test: `tests/Unit/Services/InventoryDailyAggregationServiceTest.php` (extend), `tests/Feature/Sync/InventoryDayBuilderTest.php` (extend or create)

**Interfaces:**
- Produces: daily rows per `(product_id, warehouse_id, date)`; aggregation fills columns using the reason→column table in spec §2.
- Produces: `InventoryDailyAggregationService::assertNoOpenDocuments(): void` — the existing open-document checks moved into one method so Parts C/E/F add their checks in one place.

- [ ] **Step 1: Write failing tests**
```php
it('opens a day with one row per product per warehouse that has stock');
it('aggregates transfer, manufacturing and issue reasons into their own columns');
it('keeps stocktaking out of the aggregated columns');           // shows as deviation, like today
it('keeps sending the old payload shape (one row per product, summed across warehouses) until the platform PR lands');
// The new payload (warehouse dimension + transfer/manufacturing/issue columns) is built in a separate task
// together with the platform PR — see spec §12.3.
```
- [ ] **Step 2:** run → FAIL
- [ ] **Step 3: Implement.** Group SQL by `m.product_id, m.warehouse_id`; open/close iterate `inventory_items` (product, warehouse) pairs.
- [ ] **Step 4:** run → PASS
- [ ] **Step 5: Commit** `feat(inventory): daily stock rows per warehouse`

### Task A5: Existing documents pick a warehouse

**Files:**
- Create: migration `2026_11_10_000003_add_warehouse_to_stock_documents.php` (purchase_invoices, return_purchase_invoices, wastes, stocktakings; backfill default)
- Modify: `PurchaseService.php`, `WasteService.php`, `StocktakingService.php`, the four Filament resources' forms/tables (warehouse select, active only, default preselected), models + factories
- Test: `tests/Feature/Inventory/DocumentWarehouseTest.php`

- [ ] **Step 1: Write failing tests**
```php
it('closing a purchase invoice into Main adds stock to Main only');
it('closing a purchase return removes from the invoice warehouse');
it('closing a waste removes from its warehouse');
it('a stocktaking of Grill compares and adjusts Grill balances only'); // Main untouched
```
- [ ] **Step 2:** run → FAIL
- [ ] **Step 3: Implement** — pass `warehouse_id` on every item line built by these services.
- [ ] **Step 4:** run + `./vendor/bin/pest tests/Unit/Services/StocktakingServiceTest.php tests/Feature/PurchaseCostUpdateSettingTest.php` → PASS
- [ ] **Step 5: Commit** `feat(inventory): purchases, returns, waste and stocktaking choose a warehouse`

### Task A6: Stock report per warehouse

**Files:**
- Modify: `app/Services/Reports/StockReportService.php`, `app/Filament/Pages/Reports/StockReport.php`
- Test: `tests/Feature/Reports/StockReportServiceTest.php` (extend)

**Interfaces:**
- Produces: `StockReportService::query(string $start, string $end, bool $useLive, ?int $warehouseId = null)`; `calculate()` uses
  `expected = start + incoming + transfer_in + manufacturing_in − sales − return_waste − transfer_out − manufacturing_out − issue`.

- [ ] **Step 1: Write failing tests**
```php
it('gives the same numbers as before for a single-warehouse restaurant'); // snapshot from the pre-upgrade fixture
it('shows Grill: start 2 + transfer in 10 − sales 9 = expected 3, counted 2.5 → deviation −0.5 × 85 = −42.50');
it('cancels transfers out in the all-warehouses view');
```
- [ ] **Step 2:** run → FAIL
- [ ] **Step 3: Implement** the filter (Filament select, default "كل المخازن") and new columns.
- [ ] **Step 4:** run → PASS, then `composer review` → green
- [ ] **Step 5: Commit** `feat(reports): stock report per warehouse`

---

## Part B — Costing methods (Strategy pattern)

Simple idea: one setting picks how cost moves. Example with 10 kg @ 20 on the shelf and a purchase of 10 kg @ 30:
Off → 20 · Immediate → 30 · Average → 25.

### Task B1: Strategy interface, resolver, setting, Off & Immediate, cost history

**Files:**
- Create: `app/Costing/CostingStrategy.php`, `app/Costing/CostingMethod.php` (enum `off|immediate|weighted_average`, Arabic labels), `app/Costing/CostingStrategyResolver.php`, `app/Costing/ConsumedCost.php` (DTO), `app/Costing/Strategies/OffCostingStrategy.php`, `ImmediateCostingStrategy.php`, `app/Costing/CostHistoryRecorder.php`, `app/Models/ProductCostHistory.php`, migrations `create_product_cost_histories_table`, `replace_cost_update_setting_with_costing_method`
- Modify: `app/Enums/SettingKey.php` (`COSTING_METHOD`, default `immediate`), `SettingsService.php` (`getCostingMethod(): CostingMethod`), `app/Filament/Pages/Settings.php` (select), `AppServiceProvider` (bind resolver)
- Test: `tests/Unit/Costing/OffAndImmediateStrategyTest.php`, `tests/Feature/Costing/CostingSettingMigrationTest.php`

**Interfaces (fixed here — later tasks use exactly these):**
```php
interface CostingStrategy {
    public function method(): CostingMethod;
    /** incoming movement already saved with quantity, warehouse, reason, unit_cost (may be null) */
    public function receive(InventoryItemMovement $in): void;          // sets unit_cost/total_cost if null, may change products.cost
    public function consume(InventoryItemMovement $out): ConsumedCost; // sets unit_cost/total_cost on $out
    public function reverse(InventoryItemMovement $original, InventoryItemMovement $in): void; // order cancel/return
    public function currentUnitCost(Product $product): float;
    public function stockValue(Product $product, ?int $warehouseId = null): float; // qty × current cost; null = all warehouses
}
final class ConsumedCost { public function __construct(public float $quantity, public float $totalCost) {} public function unitCost(): float; }
CostHistoryRecorder::record(Product $p, float $old, float $new, CostingMethod $m, ?Model $source): void // no row when old == new
```
- Rules per method (spec §3 and §12.4–12.5): Immediate changes `products.cost` only for reasons `purchase` and `manufacturing_in`. Off never changes it, but an incoming movement keeps its own `unit_cost` (the real invoice price). Cost changes go through `$product->save()` so `ProductObserver` cascades recipe costs.

- [ ] **Step 1: Write failing tests**
```php
it('maps the old setting: true → immediate, false → off');
it('off: buying at 30 keeps cost 20 but records 30 on the movement');
it('immediate: buying at 30 makes cost 30 and writes a history row 20 → 30');
it('immediate: an order return does not change cost');
it('consume values the movement at the current cost'); // 3 kg rice × 25 = 75
it('changing a raw cost updates the menu item that uses it');   // existing cascade still fires
it('a manual cost edit in the product form writes a history row');
```
Also replace `tests/Feature/PurchaseCostUpdateSettingTest.php` and every caller of `shouldUpdateProductCostOnPurchase()` / `isProductCostUpdatePrevented()` with `getCostingMethod()`.
- [ ] **Step 2:** run → FAIL
- [ ] **Step 3: Implement.**
- [ ] **Step 4:** run → PASS
- [ ] **Step 5: Commit** `feat(costing): costing strategy interface with off and immediate methods`

### Task B2: Weighted average strategy

**Files:** Create `app/Costing/Strategies/WeightedAverageCostingStrategy.php`; Test `tests/Unit/Costing/WeightedAverageStrategyTest.php`

- [ ] **Step 1: Write failing tests**
```php
it('averages: 10 @ 20 + buy 10 @ 30 → 25');
it('uses stock from all warehouses for the old quantity');   // Main 6 + Grill 4 @ 20, buy 10 @ 30 → 25
it('treats a negative old quantity as zero: −5 then buy 10 @ 30 → 30');
it('does not change the average on consume, purchase return or stocktaking surplus');
it('re-averages an order return at the original sale unit cost'); // 10 @ 25 on hand, return 2 sold @ 19 → (250+38)/12 = 24
it('includes free purchase lines: 10 @ 20 + 10 @ 0 → 10');   // Review Focus 2
it('locks the product row while recalculating the average');
```
- [ ] **Step 2–4:** run → FAIL, implement, run → PASS
- [ ] **Step 5: Commit** `feat(costing): weighted average method`

### Task B3: Wire the strategy into StockService, purchases and the method switch

**Files:**
- Modify: `StockService.php` (call `receive`/`consume` per movement inside the transaction; transfers bypass — Task C1), `PurchaseService.php` (purchase lines carry `unit_cost = price`), `app/Models/Product.php` (`updateManufacturedCost` cascades up nested menu items)
- Delete: `app/Services/ProductCostManagementService.php` and its unit test (replaced)
- Create: `app/Costing/CostingMethodSwitcher.php` — `switchTo(CostingMethod $m): void`
- Modify: `app/Filament/Pages/Settings.php` (changing the select calls the switcher; error if a shift is open)
- Modify: `StockReportService.php` — value columns use `CostingStrategy::stockValue($product, $warehouseId)` (labelled "قيمة حالية")
- Test: `tests/Feature/Costing/PurchaseCostingTest.php`, `tests/Feature/Costing/MethodSwitchTest.php`

- [ ] **Step 1: Write failing tests**
```php
it('closing an invoice under average: Main had 10 @ 20, invoice 10 @ 30 → cost 25 and history "20 → 25, invoice #N"');
it('sale movements carry real cost from the active strategy');
it('nested menu item cost follows a raw cost change two levels up');
it('refuses to switch method while a shift is open');
it('switching method keeps the current product cost and writes a history row');
```
- [ ] **Step 2–4:** run → FAIL, implement, run → PASS (+ whole `tests/Feature/Orders` folder)
- [ ] **Step 5: Commit** `feat(costing): purchases and stock movements use the costing method`

### Task B4: Write the real cost back to the order

**Files:**
- Modify: `app/Services/Orders/OrderStockConversionService.php` (`removeStockForCompletedOrder` returns movements and maps them back to order items), `app/Services/Orders/OrderService.php::completeOrder` (after stock removal: update `order_items.cost`, recalc `orders.profit` with the existing calculation service)
- Test: `tests/Feature/Orders/OrderRealCostTest.php`

**Algorithm (not obvious from the signature):** for each component product, split its movement `total_cost` across order items by each item's share of that component's quantity; `order_item.cost = item total cost ÷ item quantity` (round 2).

- [ ] **Step 1: Write failing tests**
```php
it('writes the real cost: added at 20, purchase mid-shift, completed at 23 → order_items.cost 23');
it('splits a shared component by quantity share');  // 2 burgers + 1 cheeseburger share bun
it('recalculates order profit from the new costs');
it('does not rewrite cost when a completed order is cancelled');
```
- [ ] **Step 2–4:** run → FAIL, implement, run → PASS
- [ ] **Step 5: Commit** `feat(orders): store real consumed cost on completed orders` → then `composer review`

---

## Part C — Transfers

### Task C1: Stock transfer voucher

**Files:**
- Create: migration `create_stock_transfers_tables`, `app/Models/StockTransfer.php`, `StockTransferItem.php`, factories, `app/Services/StockTransferService.php`, `app/Filament/Resources/StockTransfers/…` (resource, pages, items relation manager, "تأكيد التحويل" action), `app/Policies/StockTransferPolicy.php`
- Modify: `StockService.php` (add `transfer`), `InventoryDailyAggregationService::assertNoOpenDocuments`, seeder catalog (`stock_transfers`)
- Test: `tests/Feature/Inventory/StockTransferTest.php`

**Interfaces:**
- `StockService::transfer(int $fromWarehouseId, int $toWarehouseId, array $items, Model $ref): Collection` — creates `transfer_out` + `transfer_in` pairs at `products.cost`, **no strategy calls**.
- `StockTransferService::confirm(StockTransfer $t): void` — validates from ≠ to and lines are raw/consumable, sets item costs, `total_cost`, `closed_at`, `closed_by`, `business_date`.

- [ ] **Step 1: Write failing tests**
```php
it('moves 10 kg marinated chicken Prep → Grill at 85: 850 out, 850 in, cost unchanged');
it('allows taking a source below zero; all-warehouse total unchanged'); // Review Focus 5: Prep 3, send 5
it('rejects same source and destination');
it('rejects manufactured (menu) products');
it('cannot edit a confirmed transfer');
it('blocks closing the day while a draft transfer exists');
```
- [ ] **Step 2–4:** run → FAIL, implement, run → PASS
- [ ] **Step 5: Commit** `feat(inventory): stock transfer vouchers`

---

## Part D — Two-level categories & routing

Simple idea: "Drinks" (main) sends stock usage to Bar; "Hot drinks" (sub) is hidden for Delivery.

### Task D1: Category tree, warehouse links, blocked order types

**Files:**
- Create: migration `add_hierarchy_and_routing_to_categories` (`categories.parent_id`, `categories.warehouse_id`, `products.warehouse_id`, table `category_blocked_order_types`)
- Modify: `app/Models/Category.php`, `Product.php`, `app/Filament/Resources/Categories/…` (parent, warehouse, blocked types), product resources (`ConsumableProducts`, `ManufacturedProducts`: warehouse override), kitchen station lookup (where KDS reads `category.kitchen_station_id`)
- Test: `tests/Feature/Catalog/CategoryTreeTest.php`

**Interfaces:**
- `Category::mainCategory(): Category` (self if no parent); `Category::blockedOrderTypeIds(): array` (own + parent's); `Category::effectiveKitchenStationId(): ?int` (own ?? parent's)
- `Product::stockWarehouseId(): int` = own (if active) ?? main category's (if active) ?? default.

- [ ] **Step 1: Write failing tests**
```php
it('allows only two levels');                        // parent with a parent → validation error
it('keeps existing categories as main categories');
it('inherits blocks from the main category');        // Desserts blocked for takeaway → sub "Cakes" blocked too
it('lets a sub-category add its own block');
it('uses the parent kitchen station when the sub has none');
it('routes: product override > main category > default');
```
- [ ] **Step 2–4:** run → FAIL, implement, run → PASS
- [ ] **Step 5: Commit** `feat(catalog): main and sub categories with warehouse routing`

### Task D2: Sales draw from the routed warehouse

**Files:** Modify `OrderStockConversionService.php`, `OrderReturnService.php`; Test `tests/Feature/Orders/OrderStockRoutingTest.php`

- [ ] **Step 1: Write failing tests**
```php
it('a shish tawook plate (main category Grill) deducts all components from Grill');
it('lemon mint in Kitchen with product override Bar deducts from Bar');
it('nested recipe components use the warehouse of the sold item');
it('falls back to default when the routed warehouse is inactive'); // Review Focus 4
it('cancel returns stock to the warehouse it was taken from, even after routing changed');
it('partial return uses the warehouse of the original sale movement');
```
- [ ] **Step 2–4:** run → FAIL, implement (cancel/return read the order's original `order` movements), run → PASS
- [ ] **Step 5: Commit** `feat(orders): route stock deduction by category warehouse`

### Task D3: Hide blocked categories in POS and web, reject on the server

**Files:**
- Modify: `app/Http/Controllers/OrderController.php::manage` (tree payload + `blocked_order_type_codes` per category), `WebOrderController.php`, `Api/ApiController.php` (add `parent_id`), the add-item path in `app/Services/Orders/` (validation), `resources/js/Pages/Orders/ManageOrder.tsx` and its category list component (main tabs → sub chips; filter by `order.type`)
- Test: `tests/Feature/Orders/CategoryOrderTypeBlockTest.php`

- [ ] **Step 1: Write failing tests**
```php
it('marks hot drinks as blocked for delivery in the POS payload');
it('rejects adding a blocked item with an Arabic message');
it('allows changing an open order to delivery and keeps the blocked items');
it('hides blocked categories on the web storefront for its order type');
```
- [ ] **Step 2–4:** run → FAIL, implement, run → PASS; `npm run build` → succeeds
- [ ] **Step 5: Commit** `feat(pos): hide categories blocked for the order type`

### Task D4: Products sales report grouped main → sub → product

**Files:** Modify `app/Services/ProductsSalesReportService.php`, `app/Filament/Pages/Reports/ProductsSalesPerformanceReport.php`; Test `tests/Unit/Services/ProductsSalesReportServiceTest.php` (extend)

- [ ] **Step 1: Write failing tests**
```php
it('groups sales by main category with sub-category subtotals');
it('category summary uses the main category');
it('filters by main and sub category');
```
- [ ] **Step 2–4:** run → FAIL, implement, run → PASS
- [ ] **Step 5: Commit** `feat(reports): products sales by main and sub category` → `composer review`

---

## Part E — Manufacturing orders

Simple idea: 20 kg chicken + 1 L sauce out of Main, 18.5 kg marinated chicken into Prep; cost = what went in ÷ what came out.

### Task E1: Manufacturing recipes

**Files:** Create migration `create_manufacturing_recipes_tables` (recipes, inputs, outputs — spec §5), models, factories, `app/Filament/Resources/ManufacturingRecipes/…`, policy, seeder catalog (`manufacturing_recipes`). Test `tests/Feature/Manufacturing/ManufacturingRecipeTest.php`

- [ ] **Step 1: Write failing tests**
```php
it('requires exactly one main output');
it('requires percent outputs to total under 100');
it('accepts only raw materials and consumables as inputs and outputs');
it('rejects a product that is both input and output');
```
- [ ] **Step 2–4:** run → FAIL, implement, run → PASS
- [ ] **Step 5: Commit** `feat(manufacturing): batch recipes with main and extra outputs`

### Task E2: Manufacturing order draft (scaling + editable inputs)

**Files:** Create migration `create_manufacturing_orders_tables`, models, `app/Services/Manufacturing/ManufacturingOrderService.php` (`createFromRecipe(ManufacturingRecipe $r, int $sourceWarehouseId, int $destinationWarehouseId, float $plannedMainQuantity): ManufacturingOrder`), `app/Filament/Resources/ManufacturingOrders/…`, policy, seeder catalog. Test `tests/Feature/Manufacturing/ManufacturingOrderDraftTest.php`

- [ ] **Step 1: Write failing tests**
```php
it('scales the recipe: main 4 kg, plan 8 → ×2 → 20 kg chicken, 0.4 kg spices, 1 L oil');
it('copies recipe lines so later recipe edits do not change the order');
it('lets the user edit input quantities and add an extra input while draft');
it('blocks closing the day while a draft manufacturing order exists');
```
- [ ] **Step 2–4:** run → FAIL, implement, run → PASS
- [ ] **Step 5: Commit** `feat(manufacturing): manufacturing order drafts`

### Task E3: Cost allocator (pure class)

**Files:** Create `app/Services/Manufacturing/OutputCostAllocationService.php`; Test `tests/Unit/Manufacturing/OutputCostAllocatorTest.php`

**Interfaces:** `allocate(float $pot, array $outputs): array` — each output `['key', 'is_main', 'cost_method' => 'percent'|'fixed', 'cost_value', 'actual_quantity']` → returns `[key => ['allocated_cost', 'unit_cost']]`; throws `ManufacturingException` (Arabic message) when fixed total > pot.

Order of work (spec §5 step 4): fixed first → percent of what's left → main = pot − everything else; percent output with actual 0 gives its share to main; fixed with actual 0 gets 0.

- [ ] **Step 1: Write failing tests**
```php
it('splits pot 780: bones 10, thighs 270, skin 20, breast 480 → unit 10 / 60 / 40 / 120');
it('breast at 3.8 kg instead of 4 → still 480 → 126.32/kg');
it('gives a zero-quantity percent output share to main');
it('refuses when fixed outputs exceed the pot');
it('sum of shares equals the pot exactly: pot 100.00, 33.333% → 33.33 + 66.67'); // Review Focus 3
```
- [ ] **Step 2–4:** run → FAIL, implement, run → PASS
- [ ] **Step 5: Commit** `feat(manufacturing): output cost allocation`

### Task E4: Complete a manufacturing order

**Files:** Modify `ManufacturingOrderService.php` (`complete(ManufacturingOrder $o, array $actualOutputQuantities): void`), Filament "إكمال التصنيع" action (form: actual qty per output); Test `tests/Feature/Manufacturing/ManufacturingOrderCompleteTest.php`

Flow in one transaction: `removeStock` inputs from source (`manufacturing_out`) → pot = sum of returned movement costs → `OutputCostAllocationService` → `addStock` outputs to destination (`manufacturing_in`, `unit_cost` = allocated unit) → strategy `receive` → store variance (`actual − expected`, × unit cost) on outputs and planned vs actual on inputs.

- [ ] **Step 1: Write failing tests**
```php
it('takes 20 kg chicken + 1 L sauce from Main and puts 18.5 kg marinated chicken in Prep');
it('costs the output from real input cost: 1640 / 18.5 = 88.65');
it('under average mixes 18.5 @ 88.65 with the marinated chicken already on hand');
it('under off records 88.65 on the movement but keeps product cost');
it('logs yield variance: expected 19, actual 18.5 → −0.5 kg, −44.33 EGP');
it('works when source and destination are the same warehouse');
it('requires main actual quantity above zero');
it('is read-only once completed');
```
- [ ] **Step 2–4:** run → FAIL, implement, run → PASS
- [ ] **Step 5: Commit** `feat(manufacturing): complete orders with costing and yield variance` → `composer review`

---

## Part F — Stock issues

### Task F1: Stock issue voucher (إذن صرف)

**Files:** Create migration `create_stock_issues_tables`, models, factories, `app/Services/StockIssueService.php` (`confirm(StockIssue $i): void`), `app/Filament/Resources/StockIssues/…` (destination field = text input with datalist of previous distinct destinations; read-only price column showing the current cost), policy, seeder catalog (`stock_issues`); modify `assertNoOpenDocuments`. Test `tests/Feature/Inventory/StockIssueTest.php`

- [ ] **Step 1: Write failing tests**
```php
it('issues 3 kg rice at cost 25 → 75, no margin');
it('stores the cost at confirm time, even if the cost changed since the draft');
it('accepts only raw materials and consumables');
it('records destination, user and warehouse on the movement');
it('suggests previously used destinations');
it('is not counted in sales, shift money or profit');
it('blocks closing the day while a draft issue exists');
```
- [ ] **Step 2–4:** run → FAIL, implement, run → PASS
- [ ] **Step 5: Commit** `feat(inventory): stock issue vouchers at cost price`

---

## Part G — Inventory reports

All in `app/Services/Reports/Inventory/` + `app/Filament/Pages/Reports/`, reading `inventory_item_movements`. Shared filter set: date range, warehouse, main/sub category, product. Excel export the same way existing reports do. Report view permissions follow the existing report permission pattern in the seeder.

### Task G1: Item ledger (كشف حركة صنف)

**Files:** `ItemLedgerReportService.php` (`rows(int $productId, ?int $warehouseId, string $from, string $to): Collection`), `ItemLedgerReport.php`; Test `tests/Feature/Reports/ItemLedgerReportTest.php`

- [ ] **Step 1: Write failing tests**
```php
it('lists 11/01 purchase +20 (bal 20), 11/02 manufacturing −10 (bal 10), 11/03 waste −0.5 (bal 9.5) with values 1400 / 700 / 35');
it('starts the running balance from the quantity before the date range');
it('shows the user and a link to each document');
```
- [ ] **Step 2–5:** FAIL → implement → PASS → commit `feat(reports): item ledger`

### Task G2: Movement summary (ملخص حركة المخزون)

**Files:** `MovementSummaryReportService.php`, `MovementSummaryReport.php`; Test `tests/Feature/Reports/MovementSummaryReportTest.php`

- [ ] **Step 1: Write failing tests**
```php
it('totals quantity and value per document type for Main in November');
it('quantities match the stock report for the same filters');
it('drills down to the documents behind a line');
```
- [ ] **Step 2–5:** FAIL → implement → PASS → commit `feat(reports): stock movement summary`

### Task G3: Purchases, waste and stocktaking reports

**Files:** `PurchasesReportService.php` (by supplier/product/warehouse, avg price), `WasteReportService.php`, `StocktakingReportService.php` (expected vs actual, difference qty + value) + pages; Test `tests/Feature/Reports/InventoryDocumentReportsTest.php`

- [ ] **Step 1: Write failing tests** — one per report with a 2-document fixture asserting totals (e.g. purchases: 10 @ 20 + 10 @ 30 → qty 20, value 500, avg 25).
- [ ] **Step 2–5:** FAIL → implement → PASS → commit `feat(reports): purchases, waste and stocktaking reports`

### Task G4: Issues, transfers, manufacturing yield and cost history reports

**Files:** `StockIssuesReportService.php` (by destination/warehouse/user), `TransfersReportService.php`, `ManufacturingYieldReportService.php` (avg variance % per output product), `CostHistoryReportService.php` + pages; Test `tests/Feature/Reports/InventoryActivityReportsTest.php`

- [ ] **Step 1: Write failing tests**
```php
it('staff meals in November total 4,320');
it('transfers report shows from, to, qty and value');
it('marination loses 3% on average across three orders');   // −2%, −3%, −4%
it('cost history lists 20 → 25, weighted_average, invoice #55, user');
```
- [ ] **Step 2–5:** FAIL → implement → PASS → commit `feat(reports): issues, transfers, yield and cost history` → `composer review`

---

## Done when
- Every Part's tests pass and `composer review` is green.
- The spec's anti-goals hold: parity test (A6) passes, quantities agree across reports (G2), allocator refuses negative main cost (E3).
