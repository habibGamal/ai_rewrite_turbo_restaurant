# Warehouses, Cost Valuation, Manufacturing, Category Routing & Stock Issues — Design

Date: 2026-11-09
Workstreams: Inventory & Supply Chain / Production & Prep / Catalog Taxonomy / Stock Movements
App: `larament/` (Laravel 11, Filament v4, React + Inertia). Arabic UI, EGP.

This document covers four features that sit on one shared base: **warehouses + a costed movement ledger**. Build order matters (see "Build order" at the end). The language is kept simple on purpose, with a worked example for every rule.

Marked **(my call)** = a decision made while writing this design, not explicitly discussed with the owner. Everything else was agreed in conversation.

---

## 0. The restaurant story (why this exists)

A real restaurant that will use this:

1. **Main warehouse (المخزن الرئيسي)** — all purchases arrive here. Example: invoice for 50 kg chicken and 10 L oil.
2. **Manufacturing order (أمر تصنيع)** — takes raw items from Main and makes semi-finished items into the **Prep warehouse (مخزن التجهيزات)**. Example: 20 kg chicken + 1 L marinade → 18.5 kg "marinated chicken".
3. **Transfer (تحويل)** — Prep → **Grill warehouse (مخزن الشوايا)** (e.g. 10 kg marinated chicken), Prep → **Bar warehouse (مخزن البار)**.
4. **Sales** — a "shish tawook plate" sold in POS deducts its recipe from **Grill**; a "mango juice" deducts from **Bar**.

Today the system has one stock only, and purchase cost just overwrites the product cost with the last price. This blocks the story above.

### Current state (verified in code)

- `inventory_items` — one row per product (`unique(product_id)`), no warehouse.
- `inventory_item_movements` — product, operation in/out, quantity (`decimal(8,2)`), reason, morph reference. **No cost, no user, no warehouse.**
- `inventory_item_movement_daily` — one row per product per business day (start / incoming / sales / return_sales / return_waste / end). Built by `InventoryDailyAggregationService` (open day / close day / aggregate). The stock report (`App\Services\Reports\StockReportService`) reads it.
- `StockService::addStock/removeStock(items, reason, referenceable)` — the single entry point for every stock change (orders, order returns, purchases, purchase returns, waste, stocktaking). Negative stock is allowed (`ALLOW_INSUFFICIENT_STOCK = true`).
- `ProductCostManagementService::updateProductCostsWithAverage` — despite the name it sets `cost = last purchase price`. Controlled by setting `UPDATE_PRODUCT_COST_ON_PURCHASE` (on/off).
- `ProductObserver::saved` — when a non-manufactured product is saved, every manufactured product that uses it recalculates its cost (`Product::updateManufacturedCost`). This is the "BOM cost" cascade; we keep and extend it.
- `OrderStockConversionService` — breaks sold manufactured items into raw/consumable components (recursive) and calls `removeStock` with reason `order`; cancel → `addStock` with `order_return`.
- `ProductType`: `manufactured` (menu item with recipe, **no stock row**), `raw_material`, `consumable`.
- `categories` — flat (`name`, `kitchen_station_id`, `show_on_kds`, `prep_minutes`).
- Order types are dynamic: table `order_types` (`code`, `label`, `behavior`, …); model `OrderTypeRow`. Orders store the type code.
- POS loads categories in `OrderController::manage`; web storefront in `WebOrderController`; external API in `Api/ApiController`.
- Permissions: Spatie, catalogued in `database/seeders/RolesAndPermissionsSeeder::permissionsCatalog()`, run from `deploy.sh`. Policies extend `BasePolicy` with `$resource`.
- Sync: when a day closes and a `PlatformConnection` is active, `InventoryDay` + `DaySummary` documents are queued (`App\Sync`). The inventory-day builder reads the daily table.
- `closeDay()` refuses to close while purchase invoices / returns / wastes / stocktakings are open.

---

## 1. Goals, non-goals, anti-goals

### Goals
- Stock is tracked **per warehouse**. Every stock change knows which warehouse it touched.
- Every movement carries its **money value** (unit cost, total cost) and **who** did it.
- The restaurant picks **one costing method**: Off / Immediate / Weighted Average — built with the **Strategy pattern** so FIFO can be added later as a fourth class (Appendix A).
- Purchases, manufacturing and issues update/consume cost according to that method, and recipe (BOM) costs follow automatically.
- Transfer vouchers, Manufacturing orders, Stock issue vouchers in Filament.
- Two-level categories, warehouse routing by main category (product can override), order-type blocking per category.
- Reports: per-warehouse stock report, item ledger, movement summary, per-document reports, cost history, yield variance.

### Non-goals (v1)
- Different cost for the same product in different warehouses (one cost per product — agreed).
- "In transit" transfers (transfers land immediately — agreed).
- Issuing menu (manufactured) items — issues accept raw materials and consumables only (agreed).
- Accounting journal entries / general ledger.
- **FIFO is postponed** (owner decision, see Appendix A for why and for the ready design).
- Undoing a completed transfer / manufacturing order / issue (fix with an opposite document) **(my call)**.
- Syncing the new document types to the central platform **(my call)**.

### Anti-goals (would be a failure even if it "works")
- After upgrade, a single-warehouse restaurant sees **different numbers** than before on day one.
- Stock report, item ledger and summary report disagree with each other for the same filters.
- A manufacturing order produces a **negative** unit cost.

---

## 2. Warehouses (المخازن)

### Data
`warehouses`: `id`, `name`, `is_default` (exactly one true), `is_active`, timestamps.

- Filament `WarehouseResource` (Inventory group). Can't delete or deactivate the default; can't delete a warehouse that has movements (deactivate instead).
- Migration creates **"المخزن الرئيسي"** as default and moves all current stock there.

### Changes to existing tables
| table | change |
|---|---|
| `inventory_items` | add `warehouse_id` (FK, not null after backfill). Unique becomes `(product_id, warehouse_id)`. |
| `inventory_item_movements` | add `warehouse_id`, `unit_cost decimal(12,4)`, `total_cost decimal(14,2)`, `user_id` (nullable). Widen `quantity` to `decimal(12,3)` to match stock precision. Index `(warehouse_id, product_id, created_at)`. |
| `inventory_item_movement_daily` | add `warehouse_id`. Unique becomes `(product_id, warehouse_id, date)`. Add columns: `transfer_in_quantity`, `transfer_out_quantity`, `manufacturing_in_quantity`, `manufacturing_out_quantity`, `issue_quantity` (all default 0). |
| `purchase_invoices`, `return_purchase_invoices`, `wastes`, `stocktakings` | add `warehouse_id` (default = default warehouse for old rows). |

Backfill: all existing rows get the default warehouse id. Old movements get `unit_cost = products.cost` at migration time and `total_cost = qty × that` **(my call — best available, history had no cost)**.

### Rules
- A product's stock row in a warehouse is created **on first movement** there (`StockService` already inserts missing rows). New products still get a 0 row in the default warehouse (today's observer behavior).
- Purchase invoice / return / waste / stocktaking pick a warehouse (default preselected). Stocktaking counts **one warehouse**: "count Grill" compares Grill balances only.
- Negative stock stays allowed per warehouse.

Example: Main has 30 kg chicken, Grill has −2 kg (sold before transfer arrived). Both are valid balances.

### StockService changes
- `addStock` / `removeStock` take a **warehouse id** (each item line may carry its own `warehouse_id`, needed for order routing). If missing → default warehouse (keeps old callers working during rollout).
- Incoming lines may carry a `unit_cost` (purchase price, manufacturing output cost). If absent, the costing strategy decides (see §3).
- Outgoing lines get their cost from the costing strategy.
- New method `transfer(fromWarehouse, toWarehouse, items, referenceable)` — see §4.
- Every movement row gets `warehouse_id`, `unit_cost`, `total_cost`, `user_id = auth()->id()`.
- The whole thing stays inside one DB transaction with `lockForUpdate` on stock rows (as today).

### Daily aggregation & day open/close
- Day rows become per `(product, warehouse)`. Opening a day clones each `(product, warehouse)` end quantity.
- Aggregation SQL groups by `product_id, warehouse_id` and fills the new columns from reasons:

| reason (new reasons in bold) | column |
|---|---|
| `purchase` | incoming |
| `order` / `order_return` | sales / return_sales |
| `waste`, `purchase_return` | return_waste |
| **`transfer_in`** / **`transfer_out`** | transfer_in / transfer_out |
| **`manufacturing_in`** / **`manufacturing_out`** | manufacturing_in / manufacturing_out |
| **`stock_issue`** | issue |
| `stocktaking`, `adjustment`, `manual`, `initial_stock` | not aggregated (show up as deviation, same as today) |

- `closeDay()` also refuses while there are **draft** transfers, manufacturing orders or stock issues (same as purchases today).
- Sync: the `InventoryDay` payload keeps its **current shape**, summing all warehouses per product **(my call — platform unchanged)**.

### Stock report changes ("watch out")
- New **warehouse filter** (default "all warehouses").
- Formula per row:
  `expected = start + incoming + transfer_in + manufacturing_in − sales − return_waste − transfer_out − manufacturing_out − issue`
  `deviation = actual − expected`
- **All warehouses**: transfers cancel out (Prep −10, Grill +10 → net 0). Manufacturing does **not** cancel (inputs and outputs are different products).
- Example (Grill, one day): start 2 + transfer in 10 − sales 9 = expected 3. Counted 2.5 → deviation −0.5 kg × 85 = −42.50 EGP.
- Value columns = quantity × current product cost (§3), labelled "قيمة حالية".
- New category filters (main / sub), see §6.

---

## 3. Cost valuation — Strategy pattern (تقييم التكلفة)

### The setting
Setting `costing_method`: `off` | `immediate` | `weighted_average`. Recommended default for new installs: `weighted_average`. Replaces `UPDATE_PRODUCT_COST_ON_PURCHASE` (migration maps `true → immediate`, `false → off`). Shown as a select on the Settings page.

### The interface (shape, names are the builder's)
```php
interface CostingStrategy
{
    // stock came in (purchase, manufacturing output, stocktaking surplus, opening)
    public function receive(Product $p, float $qty, float $unitCost, Model $source): void;

    // stock went out (sale, waste, issue, manufacturing input, purchase return, stocktaking shortage)
    // returns the cost of what left
    public function consume(Product $p, float $qty, InventoryItemMovement $movement): ConsumedCost;

    // undo a previous consume (order cancel / order return)
    public function reverse(InventoryItemMovement $originalMovement, float $qty): void;

    // the price shown on the product & used by recipes
    public function currentUnitCost(Product $p): float;
}
```
Three classes: `OffCostingStrategy`, `ImmediateCostingStrategy`, `WeightedAverageCostingStrategy`, resolved from the setting by a small resolver bound in the container. `StockService` and purchase/manufacturing/issue services only talk to the interface.

### Behavior of each method

**Off (إيقاف)** — cost never changes automatically. Movements are valued at `products.cost`.
> Cost 20, buy at 30 → cost stays 20.

**Immediate (فوري / آخر سعر)** — cost becomes the last incoming unit cost.
> Cost 20, buy at 30 → cost 30.

**Weighted average (متوسط مرجح)** — on receive:
`new = (oldQty × oldCost + inQty × inCost) / (oldQty + inQty)` where `oldQty` = **total on hand across all warehouses**, and **if oldQty < 0 it is treated as 0**.
> 10 kg @ 20, buy 10 kg @ 30 → (200 + 300) / 20 = **25**.
> Balance −5, buy 10 @ 30 → old treated as 0 → **30**.
> Consume (sale/waste/issue) does not change the average.

### Switching method
- Allowed only when **no shift is open** **(my call — avoids switching mid-sale)**.
- `products.cost` keeps its current value; the new method applies from the next movement.
- Every switch is written to cost history.

### Cost history
`product_cost_histories`: `product_id`, `old_cost`, `new_cost`, `method`, `source_type`/`source_id`, `user_id`, `created_at`. Written whenever `products.cost` changes (purchase, manufacturing, manual edit, method switch).

### Recipe (BOM) cost follow-up
Keep the existing `ProductObserver::saved` cascade. Cost changes must go through **model save** (not query-builder update) so the cascade fires. Extend `updateManufacturedCost` so a manufactured item that is itself a component of another manufactured item also cascades (today it stops after one level) **(my call)**.

### Purchase invoice close (replaces `ProductCostManagementService`)
In one transaction: for each line → `strategy->receive(product, qty, price, invoiceItem)` → `StockService::addStock(..., warehouse = invoice.warehouse, unit_cost = price)`. Example (average): Main had 10 @ 20, invoice 10 @ 30 into Main → cost 25, history row "20 → 25, weighted_average, invoice #55".

---

## 4. Stock transfers (إذن تحويل)

`stock_transfers`: `id`, `from_warehouse_id`, `to_warehouse_id`, `notes`, `user_id`, `closed_at`, `closed_by`, `business_date`, `total_cost`, timestamps.
`stock_transfer_items`: `stock_transfer_id`, `product_id`, `quantity`, `unit_cost`, `total_cost`.

- Filament `StockTransferResource`. From ≠ to. Lines: raw materials and consumables only (manufactured items have no stock).
- Status **Draft → Done** (follow the existing `closed_at` pattern of purchases/wastes). Draft is editable; Done is read-only.
- **Confirm** (one transaction): for each line, movement `transfer_out` in source + `transfer_in` in destination, **same unit cost** (`products.cost` at that moment), no cost change.
  > Prep −10 kg marinated chicken @ 85 = 850 out; Grill +10 kg @ 85 = 850 in.
- Lands immediately (agreed). Mistake → make the opposite transfer.
- Permissions: `view/create/update/delete_stock_transfers` (+ confirm uses `update`).

---

## 5. Manufacturing orders (أوامر التصنيع)

### Batch recipe (separate from the sales recipe)
A menu item's sales recipe (`product_components`) is untouched. Manufacturing has its own recipe entity, because one product may be made in more than one way (e.g. marinated chicken from whole birds or from fillets) **(my call)**.

`manufacturing_recipes`: `id`, `name`, `notes`, `is_active`.
`manufacturing_recipe_inputs`: `recipe_id`, `product_id` (raw/consumable), `quantity`.
`manufacturing_recipe_outputs`: `recipe_id`, `product_id` (raw/consumable), `is_main` (exactly one), `expected_quantity`, `cost_method` (`percent` | `fixed`), `cost_value` (percent or EGP per unit; ignored for main).

Example recipe "Chicken breakdown":
- Inputs: 10 kg whole chicken, 0.2 kg spices, 0.5 L oil.
- Outputs: breast 4 kg (**main**), thighs 4.5 kg (fixed 60/kg), skin 0.5 kg (4%), bones 1 kg (fixed 10/kg).

Validation: one main; sum of percents < 100; output products ≠ input products.

### The order
`manufacturing_orders`: `id`, `manufacturing_recipe_id`, `source_warehouse_id`, `destination_warehouse_id` (can be the same), `planned_main_quantity`, `notes`, `user_id`, `closed_at`, `closed_by`, `business_date`, `total_input_cost`, timestamps.
`manufacturing_order_inputs`: `product_id`, `planned_quantity`, `actual_quantity`, `unit_cost`, `total_cost`.
`manufacturing_order_outputs`: `product_id`, `is_main`, `cost_method`, `cost_value`, `expected_quantity`, `actual_quantity`, `allocated_cost`, `unit_cost`, `variance_quantity`, `variance_value`.

- Creating: user picks recipe + warehouses + how much **main output** to make. Scale = planned_main / recipe main expected. Lines are **copied** from the recipe (so editing the recipe later doesn't change old orders).
  > Recipe main = 4 kg breast; plan 8 kg → scale ×2 → 20 kg chicken, 0.4 kg spices, 1 L oil; expected outputs ×2.
- **Inputs are editable** before completion (agreed): change quantities; adding an extra input line is also allowed **(my call)**.
- **Complete** (one transaction):
  1. Consume each input from the **source** warehouse (reason `manufacturing_out`) → cost from the strategy (current cost).
  2. **Pot** = sum of input costs.
  3. User enters **actual output** quantities (main must be > 0; others may be 0).
  4. Allocate the pot:
     - a. **Fixed** outputs first: `actual_qty × fixed price`, subtracted from the pot.
     - b. **Percent** outputs: their percent of **what's left after fixed**.
     - c. **Main** gets the rest (calculated as remainder → no rounding loss).
     - d. Percent output with actual 0 → its share goes to main. Fixed output with actual 0 → 0.
     - e. If fixed total > pot → **refuse** with a clear Arabic message (main would be negative).
  5. `unit_cost = allocated / actual_qty` for each output; `strategy->receive(...)` (average → mixed in; immediate → becomes cost; off → product cost untouched, movement still records it) and add stock to the **destination** (reason `manufacturing_in`).
  6. Variance per output: `actual − expected`, value = variance × unit cost.

Worked example — pot 780:

| output | method | calc | share | actual | unit cost |
|---|---|---|---|---|---|
| bones | fixed 10/kg | 1 × 10 | 10 | 1 kg | 10 |
| thighs | fixed 60/kg | 4.5 × 60 | 270 | 4.5 kg | 60 |
| skin | 4% | 4% × (780 − 280) | 20 | 0.5 kg | 40 |
| breast | main | 500 − 20 | 480 | 4 kg | **120** |

If breast came out 3.8 kg instead of 4: still 480 → 126.32/kg, variance −0.2 kg × 126.32 = −25.26 EGP.

- Input variance (planned vs actual input) is also stored per input line.
- Completed orders are read-only (non-goal: undo).
- Permissions: `view/create/update/delete_manufacturing_orders`, `…_manufacturing_recipes`.

---

## 6. Two-level categories & stock routing (التصنيفات وتوجيه السحب)

### Data
- `categories.parent_id` (nullable self FK). Max **two levels**: a parent must not have a parent; a category that has children can't get a parent.
- `categories.warehouse_id` (nullable) — "withdraw from" warehouse, meaningful on **main** categories.
- `products.warehouse_id` (nullable) — product override.
- `category_blocked_order_types`: `category_id`, `order_type_id` (FK `order_types`, cascade delete).
- Migration: every existing category becomes a **main** category; products stay where they are.
- "Main category of a product" = `category.parent ?? category`.
- Kitchen routing: a sub-category with no `kitchen_station_id` uses its parent's **(my call — so moving products into new sub-categories doesn't break KDS)**.

### Stock routing (which warehouse a sale draws from)
`warehouse = product.warehouse_id ?? mainCategory.warehouse_id ?? default warehouse`

- "Shish tawook plate" (main category Grill → Grill warehouse): all its recipe components (marinated chicken, bread, sauce) deduct from **Grill**.
- "Lemon mint" in Kitchen category but product override = Bar → deducts from **Bar**.
- Nested recipes: every component deducts from the warehouse of the **item that was sold** **(my call — agreed as a guess)**.
- `OrderStockConversionService` returns lines with `warehouse_id`; same product in two warehouses = two lines.
- **Cancel / return**: put stock back into the warehouse it was taken from, read from the order's original `order` movements (not re-resolved), so later routing changes don't confuse returns. Partial returns: per product, use the warehouse of the original sale movement (if a product was split across warehouses, return to the first one found) **(my call)**.

### Order-type blocking
- **Block list** (not allow list) so a newly created order type is available everywhere by default.
- Inherited: blocking a main category blocks all its sub-categories; a sub can add extra blocks.
  > "Hot drinks" (sub) blocked for Delivery → hidden when the order is Delivery.
  > "Desserts" (main) blocked for Takeaway → all dessert sub-categories hidden for Takeaway.
- POS (`ManageOrder`) and web storefront hide blocked categories and their products for the order's current type.
- Server rejects **adding** a blocked item (protects API/web).
- **Changing an open order's type** to one that blocks an item already in the order: **allowed**, items stay (agreed).
- POS payload becomes a tree (main → subs → products); `Api/ApiController` adds `parent_id` (non-breaking).

### Filament
Category form: parent select (main categories only), warehouse select, blocked order types (multi-select). Product forms: optional warehouse override.

---

## 7. Stock issue vouchers (إذن صرف مخزني)

`stock_issues`: `id`, `warehouse_id`, `destination` (free text), `notes`, `user_id`, `closed_at`, `closed_by`, `business_date`, `total_cost`, timestamps.
`stock_issue_items`: `stock_issue_id`, `product_id` (raw/consumable only), `quantity`, `unit_cost`, `total_cost`.

- **Destination** typed by the user (e.g. "وجبات الموظفين", "ضيافة", "حفلة أحمد"); the field suggests previously used destinations to avoid near-duplicates.
- **Price is read-only, no margin**:
  - `products.cost` at confirm time. 3 kg rice × 25 = **75**. What you see on the draft is what gets stored.
- **Draft → Done**. Confirm deducts from the warehouse with reason `stock_issue`, cost, user, destination. Done is read-only.
- Not revenue: never counted in sales, shift money, or channel reports.
- Permissions: `view/create/update/delete_stock_issues`.

---

## 8. Reports

All read from the **same ledger** (`inventory_item_movements` with warehouse + cost + user), so numbers agree. Filters everywhere: date range, warehouse, main category, sub-category, product. Excel export. Built as Filament report pages like the existing ones in `app/Filament/Pages/Reports/`.

1. **Item ledger (كشف حركة صنف)** — one product, one or all warehouses: date, document, in, out, running balance, unit cost, value, user.
2. **Movement summary (ملخص حركة المخزون)** — per document type: quantity + value. Example (Nov, Main): purchases 85,000 / purchase returns 1,200 / transfers out 40,000 / manufacturing out 30,000 / waste 900 / issues 4,320 / stocktaking difference −650. Each line drills down to its documents.
3. **Purchases** — by supplier, product, warehouse: qty, value, average purchase price.
4. **Waste** — by product, warehouse, reason: qty, value.
5. **Stocktaking** — per count: expected vs actual, difference qty + value.
6. **Stock issues** — by destination, warehouse, user. ("Staff meals in November: 4,320 EGP".)
7. **Transfers** — from/to, qty, value.
8. **Manufacturing & yield variance** — inputs, outputs, expected vs actual, variance value; average variance % per output product.
9. **Cost history** — per product: old → new, method, document, user.
10. **Products sales report** — grouped **main category → sub-category → product** with subtotals; category summary uses the main category.
11. **Stock report** — per-warehouse changes from §2.

---

## 9. Permissions & navigation
- New permission groups in `RolesAndPermissionsSeeder::permissionsCatalog()`: `warehouses`, `stock_transfers`, `manufacturing_recipes`, `manufacturing_orders`, `stock_issues`, plus report view permissions following the existing report pattern. Policies extend `BasePolicy`.
- Settings page: `costing_method` select (and the switch rule from §3).

---

## 10. Testing expectations
Pest feature tests (SQLite) for every worked example in this document, at least:
- Upgrade: single-warehouse data → same stock report numbers before/after migration.
- Each costing strategy: receive/consume/reverse, negative-old-quantity average rule, order return re-average, method switch.
- Transfer: source −, destination +, same cost, totals unchanged across all warehouses.
- Manufacturing: allocation table above, main remainder, zero percent output → main, fixed > pot refused, variance values, edited inputs.
- Routing: main-category warehouse, product override, nested recipes, cancel returns to original warehouse.
- Blocking: inherited block hides in POS payload, server rejects add, type change keeps items.
- Issue: cost = strategy cost, not in sales totals.
- Concurrency-sensitive code uses `lockForUpdate` on the product row when the average is recalculated.

---

## 11. Build order
1. **Warehouses foundation** — tables, backfill, `StockService` warehouse param, daily aggregation per warehouse, documents pick a warehouse, stock report filter.
2. **Costing strategies** — movement costs, interface + 3 strategies, cost history, setting + switch, purchase close.
3. **Transfers.**
4. **Categories & routing** — hierarchy, routing in order stock conversion, blocking in POS/web/server, sales report grouping.
5. **Manufacturing** — recipes, orders, allocation, variance.
6. **Stock issues.**
7. **Reports** (item ledger, summary, per-document, cost history, yield).

Each step ships on its own and keeps `composer review` green.

## 12. Decisions settled after design review (all **(my call)**)

1. **Values vs quantities.** Quantities must agree exactly across all reports. Money in ledger-style reports (item ledger, summary, per-document) = each movement's own `total_cost`. Money in the stock report is a **current valuation** (qty × current unit cost) and is labelled as such. Under **Off**, a purchase movement still records the real invoice price; only `products.cost` stays untouched.
2. ~~FIFO value for one warehouse~~ — moved to Appendix A with FIFO.
3. **Sync payload (owner changed this).** The platform will be updated in its own PR to accept the new data shape. `InventoryDay` gains the new columns (`transfer_in`, `transfer_out`, `manufacturing_in`, `manufacturing_out`, `issue`) and the warehouse dimension; no folding into old columns. The exact payload shape is agreed together with the platform PR. Until that PR ships, the branch keeps sending the old shape.
4. **Reversals & returns under Average / Immediate / Off.**
   - Order cancel/return: stock comes back at the **original sale movement's unit cost**. Average re-averages with it; Immediate and Off don't change `products.cost`.
   - Purchase return: valued at **current cost**; Average does not change (standard AVCO: outgoing never changes the average); Immediate/Off unchanged.
   - Stocktaking surplus: at current cost (Average unchanged).
5. **Immediate = today + manufacturing.** Cost changes on purchase close (as today) and on manufacturing output (new feature, so no day-one difference). Nothing else changes it.
6. **Routing time.** The warehouse is resolved at **order completion** (when stock is deducted today). Changing a category's warehouse while an order is open affects that order. No `order_items` schema change.

### Owner decisions after review
7. **No conversion tool for existing semi-finished items.** Products already set up as `manufactured` (e.g. a "marinated chicken" sub-recipe) stay as they are and keep breaking down into raw items at sale time. No data migration, no "convert" button. To use manufacturing for an item, the owner sets up a raw-material product + manufacturing recipe for it themselves.
8. **Real cost is written back to the order.** On order completion, after stock is deducted, each `order_items.cost` is overwritten with the **real consumed cost per unit** from the sale movements (sum of that item's component movement costs ÷ item quantity). Profit, shift, channel reports and the `DaySummary` sync then use the real cost. Example: added at 20, a purchase closed mid-shift, real average cost at completion 23 → `order_items.cost = 23`. Order cancel after completion does not rewrite cost.
   - Builder note: when the same component serves several order items, split its movement cost by each item's share of that component's quantity.

## 13. Left to the builder
- Exact class/file names, Filament page layouts, Arabic labels (follow `lang/ar.json`).
- Whether new reasons are added to `MovementReason` as new cases (expected) and how the legacy `transfer` case is kept for old data.
- Excel export mechanism (reuse whatever existing reports use).

---

## Appendix A — FIFO (postponed, ready design)

**Why postponed (owner decision 2026-11-09):** FIFO shows one number on screen (oldest batch price) while charging another under the hood (a sale spanning batches), its cost jumps when a batch runs out mid-shift and jumps back after a cancellation, negative-stock sales are priced by estimate and never corrected, the batches are not per warehouse (so it is not physical FIFO and per-warehouse value is really an average), document order changes the day's profit, it adds locks and rows on every sale, and it is the most bug-prone part of the plan. Weighted average is accepted by IAS 2 / the Egyptian standard and gives one number everywhere.

**How to add it later:** add `fifo` to `CostingMethod` and a `FifoCostingStrategy` class; nothing in transfers, manufacturing, issues or reports needs to change because they only talk to the interface. Switching to FIFO creates an opening layer per product from current stock, so nothing is lost by postponing.

**Ready design (as agreed before postponing):**

**FIFO (الوارد أولًا يصرف أولًا)** — every receive creates a **layer** (batch). Consume eats from the **oldest layer first**.
> Layers: 10 kg @ 20, then 10 kg @ 30. Sell 12 kg → 10 from layer 1 + 2 from layer 2 = 200 + 60 = **260** recorded on the sale movement.
> `products.cost` = unit cost of the **oldest layer with stock left** (agreed; same as Odoo). Above: 20 before the sale, 30 after it. When it changes, recipe costs update.
> If no layer has stock: keep the last known cost.

#### FIFO tables
`cost_layers`: `id`, `product_id`, `source_type`/`source_id` (morph: purchase invoice item, manufacturing order output, stocktaking, opening…), `received_at`, `original_quantity`, `remaining_quantity`, `unit_cost`, timestamps. Index `(product_id, remaining_quantity, received_at, id)`. **No warehouse column** — one cost per product, so transfers never touch layers.

`cost_layer_consumptions`: `id`, `inventory_item_movement_id`, `cost_layer_id` (nullable = not covered), `quantity`, `unit_cost`, timestamps.

#### FIFO edge cases
- **Negative stock (shortfall):** layers hold 4 kg, sell 6 kg → 4 kg from layers at their price; 2 kg recorded with `cost_layer_id = null` at current cost ("not covered"). Next purchase of 10 kg @ 30 first covers the 2 kg backlog → new layer starts with `remaining = 8`. Past sale cost is **not** changed.
- **Order cancel / order return:** the quantity goes **back into the same layers** it came from (via `cost_layer_consumptions` of the original sale movement). Not-covered parts reduce the backlog instead.
- **Purchase return:** consume from **that invoice's layer first**; if it's already used, from the oldest layers.
- **Stocktaking surplus:** new layer at current cost. **Shortage:** normal consume.
- **Locking:** layers read with `lockForUpdate` ordered `received_at, id` inside the stock transaction.

#### FIFO switching and per-warehouse value
- To FIFO: delete/zero old layers, then one opening layer per product = total on-hand qty (all warehouses) @ current cost (e.g. 15 kg @ 22); negative totals → backlog, no layer.
- Away from FIFO: `products.cost` stays; layers ignored.
- Per-warehouse value = warehouse qty × (remaining layer value ÷ remaining layer qty). Example: 10 @ 20 + 10 @ 30 = 500 / 20 = 25; Grill 4 kg → 100.
- Interface needs `stockValue(Product, ?int $warehouseId)` and `consume` returning a per-layer breakdown.
