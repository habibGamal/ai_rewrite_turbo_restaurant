# Ordering Refactor, Dynamic Order Types, Cashier Tabs, External Orders, and Dine-In Floor

## Context

Order types, POS tabs, and sales columns are locked to the `OrderType` enum. A new tab (catering, lounge, an outside channel) needs a code change in the cashier desk, validation, receipts, kitchen, KDS settings, shift cards, sync, and the `CASE WHEN orders.type = "dine_in"` report queries.

Four things land on the same ordering flow:

- **The ordering core gets cleaned up first.** `OrderService`, `OrderRepository`, the order DTOs, and `OrderController` have rules spread across layers, inconsistent locking, duplicated discount math, and dead code. They are refactored before any feature is built on them. This is a refactor, not a rewrite.
- **Cashier tabs are configured, not coded.** An admin can reorder and hide every desk tab, including مصاريف, ملغي, and منتهي.
- **طلب خارجي.** A guest can be billed for an outside seller (the sauce car) on the restaurant receipt. That money is not restaurant sales or profit. The restaurant holds it and pays the seller later. The name of this bill is **طلب خارجي**. Do not call it إسناد in the UI, the code, or comments.
- **Dine-in floor.** Dine-in can use a designed table grid, or keep today's card list and typed table number. The grid belongs to the dine-in behavior. The layout is a JSON file, edited from a React + Ant Design page. It is not a Filament page and it is not a set of grid columns in MySQL.

## Decisions already made

- **A type is a catalog row plus one existing behavior.** Labels, colors, and icons are data. Behavior is a strategy. Adding a tab does not add a class.
- **Strategies are the paths that already differ in code**, not one class per current label:
  - `DineInBehavior` — table, service charge, floor presentation. Today: صالة.
  - `DeliveryBehavior` — immediate create, delivery fee from the customer, customer and driver panels. Today: دليفري.
  - `CounterBehavior` — immediate create, no table, no fee, must be paid in full. Today: تيك أواي, طلبات.
  - `CompaniesBehavior` — same create as counter, but may complete partly paid. The rest is collected later, in any shift. Today: شركات.
  - `WebDeliveryBehavior` — not created by the cashier, totals come from the website and are never recalculated, survives shift close, does not use the POS order number. Today: اونلاين دليفري.
  - `WebTakeawayBehavior` — same as web delivery, no driver, no `out_for_delivery` status. Today: اونلاين تيك أواي.
- **Companies partial payment works again.** It worked in the old app: a company order completes partly paid, and the rest is collected later from a list on the desk. In larament the pieces exist but are disconnected (see *What is wrong today*). Section 4 wires them back up through `CompaniesBehavior`.
- **Only a behavior that allows it can complete partly paid.** Today nothing stops a takeaway order completing with half its total paid. The remaining amount is then never collected. After this work, only `CompaniesBehavior` allows it.
- **Web behaviors are only for system rows.** The website API only sends `web_delivery` and `web_takeaway`. An admin-created row with a web behavior could never receive an order. Admins pick from `dine_in`, `delivery`, `counter`, and `companies`.
- **The drawer figure is the shift report, not `endShift()`.** `shifts.end_cash` and `has_deficit` are legacy and nothing depends on them. This work does not touch them. Every drawer change goes into `ShiftsReportService` (`start_cash + cash payments - expenses`).
- **`orders.type` stays a string code.** Seed `order_types` from the current enum values so old orders keep resolving. A code is fixed once it is created.
- **Do not change what `orders.total` and `orders.profit` mean.** They stay restaurant money. `OrderCalculationService` is not taught about the sauce car.
- **A طلب خارجي is a separate `ExternalOrder` attached to the host order.** It is merged only on the receipt and at the till. It is not a line flag, and it is not a second POS order.
- **Tab layout is one ordered list in settings.** It covers type tabs and system tabs. `order_types` has no `sort_order` or `show_in_pos` columns, so the layout has one source.
- **The floor grid is automatic for dine-in behavior only.** Each dine-in type has its own JSON layout file. A dine-in type runs as today's card list until its file says the grid is on.
- **The designer is an Inertia page using Ant Design**, the same UI kit as the cashier desk. Filament only links to it.
- **KISS.** No workflow engine, no second POS order, no pixel canvas, no interface per method, no repository interface for a class that has one implementation, no empty context object "for later", no base controller `rescue()` helper.

## What is wrong today

### Type rules are copied, not owned

| Rule | Where it is repeated |
|---|---|
| Requires a table | `OrderType::requiresTable()`, the `CreateOrderDTO` constructor, `OrderCreationService` (checked twice), `OrderCompletionService`, `OrderService::cancelOrder()`, `OrderService::changeOrderType()`, `ChangeOrderTypeModal.tsx`, `ManageOrder.tsx` |
| Service charge vs delivery fee | `OrderType` methods, `Order::getServiceRateAttribute()`, `OrderCalculationService::calculateServiceCharge()`, and again in the browser in `utils/orderCalculations.ts`, which only knows `delivery` and misses `web_delivery` |
| Web totals are not recalculated | `OrderCompletionService` skips `calculateOrderTotals()` when `isWebOrder()` |
| Web orders skip the POS order number | `OrderRepository::getNextOrderNumber()` lists `WEB_DELIVERY` and `WEB_TAKEAWAY` |
| Web orders survive shift close | `ShiftService::transferOpenWebOrders()`, `ShiftService::endShift()`, `OrderController::startShift()` |
| Cashier create | `createOrder()` validates against every enum case and then `match`es only five of them, so a web code fails with a generic error. `updateOrderType()` has its own `in:` list. `ChangeOrderTypeModal.tsx` has a third hardcoded list |
| Website API | `WebOrdersController` validates with `in:web_delivery,web_takeaway`. `WebApiService` calls `OrderType::from()` |
| POS tab | One React component per type, plus a hardcoded tab array and a default tab of `'dine_in'` in `Orders/Index.tsx` |
| Labels | Arabic maps in `receipt-template.blade.php`, `receipt-header.blade.php`, `orderCalculations.ts`, `helpers/orderHeader.ts`, `ChangeOrderTypeModal.tsx`, and the KDS icon map in `KdsTicketBlocks.tsx` |
| KDS | `KitchenRoutingService::targetMinutes(Product, OrderType)`. `Settings.php` builds the per-type target fields from `OrderType::cases()` in three places |
| Report column | `CASE WHEN` in `ProductsSalesReportService` and `CustomersPerformanceReportService`. Shift cards are keyed `dineIn`, `talabat`, … |
| Enum cast consumers | 22 files in `app/` use `OrderType::`, including every Filament table, exporter, and widget that renders the type badge, plus `Order::getOrderTypeLabel()`, `OrderBuilder` (`$order->type->value`), and `OrderFactory`. About 25 test files also use it |

### The ordering core

**`OrderService`** (328 lines) is the transaction boundary, which is right. But:

- **Locking is inconsistent.** Only `updateOrderItems()` and `completeOrder()` take `lockForUpdate()`. `cancelOrder()`, `changeOrderType()`, `applyDiscount()`, and `linkCustomer()` read without a lock. Saving items while another terminal completes the order can replace the items after payment is taken.
- **It prices items.** `updateOrderItems()` looks up product prices and computes `item_discount` from the percent. `OrderCalculationService::calculateDiscount()` then computes the same item discount again from the percent. Two formulas have to stay in step.
- **The discount rule is split.** "Item discounts clear the order discount" lives in `OrderService`. "Order discount clears item discounts" lives in `OrderCalculationService::applyDiscount()`, which updates one item at a time.
- **`linkCustomer()` recalculates outside a transaction.** `OrderController::updateCustomer()` edits an existing customer's `delivery_cost` and never recalculates, so the delivery fee on an open order goes stale. That is a bug.
- **Dead code:** `ALLOW_INSUFFICIENT_STOCK` with its commented-out check, `getOrderStockRequirements()`, `validateOrderStockAvailability()`, and `processOldOrderPayment()` (no route).

**`OrderCreationService`** both opens an order and replaces its items. Opening checks table availability, then `reserveTable()` checks it again.

**`OrderCompletionService`** has two payment paths with different rules. One tender goes through `processPayment()`, which silently caps the amount at the remaining balance (change is allowed). Several tenders go through `processMultiplePayments()`, which throws if the sum is over `order.total`.

**`OrderCalculationService`** logs at `info` level on every recalculation.

**`OrderRepository`** (98 lines) is a fine persistence wrapper, except:

- `getNextOrderNumber()` holds a web behavior rule.
- `getNextOrderNumber()` reads max + 1 without a lock. The unique `(shift_id, order_number)` index turns two concurrent creates into an exception.
- `getProcessingOrders()` and `getOrdersWithPaymentStatus()` are unused. In `OrderItemRepository`, `createForOrder()` and `getOrderItems()` are unused.
- `OrderRepositoryInterface`, `OrderItemRepositoryInterface`, and `PaymentRepositoryInterface` each have exactly one implementation. They are bound in `OrderServiceProvider` and never mocked in tests.

**`OrderItemRepository::updateOrderItems()`** deletes and recreates every line on every save, so item ids change on each save. Nothing links to an open order's item ids today (returns only happen after completion), so this stays. Do not link anything new to open-order item ids.

**DTOs.** `CreateOrderDTO` holds an `OrderType` enum and enforces "dine-in needs a table" in its constructor, which is a business rule inside a DTO. `OrderItemDTO` and `PaymentDTO` are fine.

**`OrderController`** (873 lines) has five jobs. `saveOrder()` reads the old items outside any lock, calls `Product::find()` once per line, and records item changes after the transaction has committed. `index()` queries `Order` directly for an unrendered tab. There is no `ShiftController` yet: the `shifts.*` routes point at `OrderController`.

**Companies partial payment is disconnected:**

- `CompaniesTab` and `ReceiveOrdersPaymentsTab` are imported in `Orders/Index.tsx` but never rendered.
- `OrderController::index()` still loads `previousPartialPaidOrders`, but nothing shows it.
- `OrderService::processOldOrderPayment()` has no route, no lock, no status check, and takes the shift from the caller.
- Nothing enforces full payment for any type.

**`ShiftsReportService` puts payments in the order's shift, not the payment's shift.** `calculateShiftStats()` sums the payments of the shift's *orders*. A collection taken today for last week's companies order has `payments.shift_id` = today, but it is counted in last week's closed shift and is missing from today's drawer. For every other payment the two shifts are the same: completion writes the order's shift, and transferred web orders move both together.

## Phase 0 — ordering refactor

Phase 0 has no type, tab, or external-order work. It leaves the till looking the same and fixes the bugs listed here. Each item gets a test first.

### OrderService

`OrderService` stays the facade the till calls. Every method that changes an order follows one shape:

```php
private function locked(int $orderId, string $notModifiableMessage, Closure $fn): Order
{
    return DB::transaction(function () use ($orderId, $notModifiableMessage, $fn) {
        $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
        if (! $order->status->canBeModified()) {
            throw new OrderException($notModifiableMessage);
        }

        return $fn($order);
    });
}
```

`updateOrderItems`, `applyDiscount`, `linkCustomer`, `linkDriver`, `updateNotes`, and `changeOrderType` use it. `completeOrder` and `cancelOrder` lock the same way, but each has its own status check. This is a private helper in one service, not a framework.

- **Pricing moves out.** `OrderItemPricer::price(array $lines): OrderItemDTO[]` loads products in one query, sets price and cost, and caps the item discount. It is the only place an item discount is computed. `OrderCalculationService::calculateDiscount()` sums the stored `item_discount` and stops re-deriving it from the percent. A test locks the totals for percent and fixed item discounts before and after this change.
- **The discount policy moves into `OrderCalculationService`.** Both directions of the item-vs-order discount rule live there. Clearing item discounts is one `update()` query, not one query per item.
- **Item change tracking moves into the transaction.** `updateOrderItems()` reads the old lines under the lock, applies the new ones, computes the differences, and calls `OrderItemChangeService::recordChanges()` and the shift log. It returns the order. `saveOrder()` becomes validate → call → flash.
- **Customer changes always recalculate.** `OrderController::updateCustomer()` calls `OrderService::linkCustomer()` in both branches (new customer and edited customer). `linkCustomer()` runs under the lock.
- **Kitchen calls stay explicit** (`syncOrder`, `autoFinishForOrder`, `cancelForOrder`, `rebroadcastForOrder`). They run inside the transaction on purpose. Do not turn them into event listeners.
- **Delete** `ALLOW_INSUFFICIENT_STOCK`, the commented stock check, `getOrderStockRequirements()`, and `validateOrderStockAvailability()`. `processOldOrderPayment()` is replaced by `collectRemaining()` in Phase C. Delete the stock-requirement methods from `OrderStockConversionService` only if nothing else calls them.

### OrderCreationService → OrderOpeningService

Rename the service to match its single job. `open(CreateOrderDTO)` persists the order, then (from Phase A on) calls `behavior->onOpened($order, $dto->opening)`. Until Phase A, it reserves the table once, with no double check. `updateOrderItems()` moves into `OrderService`, because it is three lines once pricing has moved out.

### Payments

One entry point replaces the two paths:

```php
final class Tenders            // method => amount, amounts > 0 only
{
    /** @param array<string, float> $amounts */
    public function __construct(public readonly array $amounts) {}
    public function total(): float;
    public static function fromRequest(array $validated): self;
}

OrderPaymentService::pay(Order $order, Tenders $tenders): Order
```

The rules match today's behavior and are now explicit:

- A single tender may exceed the remaining amount. The excess is change and is not recorded.
- Several tenders may not exceed the remaining amount. This throws the same Arabic message as today.

`OrderCompletionService` calls `pay()` once. `PaymentDTO` stays as the repository's input. `GuestBill` (Phase E) and `collectRemaining()` (Phase C) also work on `Tenders`, which is why this object exists.

`pay()` takes the shift to record the payment against. Completion passes the order's shift, as today. A later collection passes the current shift.

### OrderCalculationService

- Drop the `info` log.
- Keep the formula `ceil(subtotal + service + tax - discount)` and `profit = total - cost`.
- `calculateServiceCharge(Order, float $subtotal)` keeps its signature. In Phase A it delegates to `behavior->serviceAmount($order, $subtotal)`.

### Repositories

- Delete the three `Contracts/*RepositoryInterface` files and the `OrderServiceProvider` bindings. Type-hint the concrete classes.
- `getNextOrderNumber(int $shiftId, array $excludedCodes)` runs inside the create transaction after `Shift::whereKey($shiftId)->lockForUpdate()`. Then max + 1 is safe. The `while (exists)` loop stays: web orders carry the website's own number in the same shift, and the loop skips numbers they already took. Until Phase A, the caller passes the two web codes. From Phase A, it passes `$catalog->codesWhere(fn ($b) => ! $b->usesPosOrderNumber())`. The repository does not import `OrderType` or the catalog.
- Delete `getProcessingOrders()`, `getOrdersWithPaymentStatus()`, `OrderItemRepository::createForOrder()`, and `getOrderItems()`.
- `update(Order, array)` stays an untyped bag. Do not invent a typed method per column.

### DTOs

```php
final class CreateOrderDTO
{
    public function __construct(
        public readonly string $typeCode,
        public readonly int $shiftId,
        public readonly int $userId,
        public readonly OrderOpening $opening = new OrderOpening(),
        public readonly ?int $customerId = null,
        public readonly ?int $driverId = null,
        public readonly ?string $kitchenNotes = null,
        public readonly ?string $orderNotes = null,
    ) {}
}
```

No business rule in the constructor. "This type needs a table" is `DineInBehavior::onOpened()`'s job. In Phase 0, before the catalog exists, `OrderOpeningService` does that check. Delete `fromArray()`/`toArray()` if only tests use them.

`OrderOpening` holds the facts known when an order is opened:

```php
final class OrderOpening
{
    public function __construct(
        public readonly ?string $tableNumber = null,
    ) {}
}
```

A new fact (a section, a guest count) is a field here, not a new parameter on every strategy.

### OrderController and ShiftController

| Job | Methods | What this work does |
|---|---|---|
| Shift start and end | `showStartShift`, `startShift`, `endShift` | Move to a new `ShiftController`. Point the `shifts.*` routes at it. The open-web-order count in `startShift()` moves into `ShiftService`. |
| Till orders | `index`, `manage`, `createOrder`, `saveOrder`, `completeOrder`, `cancelOrder`, `updateOrderType`, `updateOrderNotes`, `applyDiscount` | Stays. This is the facade's HTTP edge. `saveOrder` loses its diff logic. `index` stops querying `Order` directly for `previousPartialPaidOrders` (Phase C moves it to the repository). Phase C adds `collectRemaining`. |
| Customers | `updateCustomer`, `quickCustomer`, `fetchCustomerInfo`, `searchCustomersByName`, `linkCustomer` | Leave, except the recalculation fix above. |
| Drivers | `updateDriver`, `quickDriver`, `fetchDriverInfo`, `linkDriver` | Leave. |
| Print, drawer, table types | `printReceipt`, `printInKitchen`, `getPrintersOfProducts`, `openCashierDrawer`, `getTableTypes` | Leave. |

Do not extract an action class per method. The try/catch + Arabic flash pattern is repeated, and it stays.

`CompaniesTab.tsx` and `ReceiveOrdersPaymentsTab.tsx` stay until Phase C replaces them.

### Phase 0 tests

- Saving items while another request holds the completion lock waits for it. The replaced items never land on a completed order.
- Editing an existing customer's `delivery_cost` on an open delivery order changes `orders.service` and `orders.total`.
- Percent and fixed item discounts give the same totals before and after `OrderItemPricer`.
- One cash tender over the due amount completes with the payment capped. Several tenders over the due amount throw.
- Two orders created in the same shift get consecutive numbers. Web orders keep their website number.
- Item changes are recorded once per save, inside the same transaction as the save.

## Target shape

```
app/Orders/OrderTypeCatalog.php
app/Orders/OrderBehaviorResolver.php
app/Orders/OrderOpening.php
app/Orders/Tenders.php
app/Orders/OrderDisplay.php               label + which blocks to show; used by blades, React, KDS
app/Orders/TabPresentation.php
app/Orders/CashierTabLayout.php           merges the settings list with the catalog
app/Orders/Behaviors/OrderTypeBehavior.php
app/Orders/Behaviors/DineInBehavior.php
app/Orders/Behaviors/DeliveryBehavior.php
app/Orders/Behaviors/CounterBehavior.php
app/Orders/Behaviors/CompaniesBehavior.php
app/Orders/Behaviors/WebDeliveryBehavior.php
app/Orders/Behaviors/WebTakeawayBehavior.php
app/Enums/OrderBehavior.php               the six behavior keys
app/Filament/Pages/CompaniesAccounts.php  outstanding balance per company customer
resources/js/Components/Orders/Index/ReceivablesTab.tsx
app/Services/Orders/OrderOpeningService.php   (was OrderCreationService)
app/Services/Orders/OrderItemPricer.php
app/Services/Orders/GuestBill.php         guest due + tender split; does not write orders.total
app/Services/Orders/ExternalOrderService.php
app/Services/Orders/FloorLayoutService.php    read/write the JSON file
app/Http/Controllers/ShiftController.php
app/Http/Controllers/ExternalOrderController.php
app/Http/Controllers/FloorLayoutController.php
resources/js/Components/Orders/Index/OrderTypeTab.tsx
resources/js/Pages/Floor/Designer.tsx
storage/app/floor-layouts/{code}.json
```

## The strategy

```php
interface OrderTypeBehavior
{
    public function cashierCanCreate(): bool;

    public function creation(): CreationMode;            // Immediate | ChooseTable | None

    public function recalculatesTotals(): bool;           // false for web: the website's totals are final

    public function serviceAmount(Order $order, float $subtotal): float;

    public function onOpened(Order $order, OrderOpening $opening): void;

    public function onClosed(Order $order): void;

    public function carriesAcrossShifts(): bool;

    public function allowsPartialPayment(): bool;         // true only for companies

    public function usesPosOrderNumber(): bool;

    public function presentation(OrderTypeRow $type): TabPresentation;

    public function display(Order $order): OrderDisplay;
}
```

- `serviceAmount` keeps the `$subtotal` argument the current code already passes. The strategy must not re-sum the items, because then the two subtotals could differ. `DineInBehavior` returns `$subtotal * service rate`. `DeliveryBehavior` returns `$order->customer?->delivery_cost ?? 0`. The others return `0`.
- `recalculatesTotals()` replaces `isWebOrder()` in `OrderCompletionService`. Without it, the web strategies would overwrite the website's totals.
- `allowsPartialPayment()` decides whether completion may leave a remaining balance. It also decides which partly-paid orders show up for later collection (section 4).
- `onOpened` validates and reserves the table for dine-in, and throws `OrderException('رقم الطاولة مطلوب')` if there is none. `onClosed` frees it. Both take only what they need.
- `OrderDisplay` is a readonly object: `label`, `color`, `icon`, `showTable`, `showCustomer`, `showAddress`, `showDriver`, `chargesService`, `chargesDeliveryFee`. The receipt blades, both receipt React templates, `ManageOrder.tsx`, `ChangeOrderTypeModal.tsx`, the KDS ticket payload, and `orderHeader.ts` read it. None of them compare type codes or keep an Arabic label map.
- `OrderBehaviorResolver` is a `match` on `order_types.behavior`. A new strategy is a class plus one match arm. A new tab is a row.

Call sites after Phase A:

- `OrderCalculationService::calculateServiceCharge()` calls `behavior->serviceAmount($order, $subtotal)`.
- `OrderOpeningService::open()` persists the order, then calls `onOpened()`.
- `OrderCompletionService` and `OrderService::cancelOrder()` call `onClosed()`. Completion calls `calculateOrderTotals()` only when `recalculatesTotals()`. From Phase C, completion refuses to leave a remaining balance unless `allowsPartialPayment()`.
- `changeOrderType()` calls `old->onClosed()`, then `new->onOpened($order, $opening)`, then recalculates, then broadcasts (see Floor).
- `ShiftService` (start, transfer, end) asks the catalog for `carriesAcrossShifts()` codes.
- `OrderRepository::getNextOrderNumber()` receives the excluded codes.
- `KitchenRoutingService::targetMinutes(Product, string $typeCode)`.
- `WebOrdersController` validates `order.type` against catalog codes whose behavior is web. `WebApiService` stores the code.

## 1. Order type catalog

### Table `order_types`

| Column | Purpose |
|---|---|
| `code` | Stable slug, unique, fixed after create. Seeded: `dine_in`, `takeaway`, `delivery`, `companies`, `talabat`, `web_delivery`, `web_takeaway`. |
| `label` | Arabic tab, receipt, KDS, and report label. |
| `color`, `icon` | Badge and tab. |
| `is_active` | An inactive type cannot be created or chosen. Old orders still show its label. |
| `is_system` | Seeded rows cannot be deleted, and their behavior cannot change. Label, color, icon, and active state can change. |
| `behavior` | One of the six keys. Admin-created rows choose `dine_in`, `delivery`, `counter`, or `companies`. |

There is no `allowed_payment_methods` column. The till does not enforce a payment method for طلبات today, and this work does not start enforcing one.

There is no `sort_order` or `show_in_pos` column. Order and visibility live in the cashier tab layout (section 2).

**Behavior lock.** A custom row's behavior can only change when the type has no open orders (`processing`, `pending`, `out_for_delivery`). Otherwise, switching لاونج from `dine_in` to `counter` would leave its `dine_tables` rows occupied forever.

**Lookups.** `OrderTypeCatalog` loads every row once per request and keeps them in memory. It is cleared on save. `Order::behavior()` resolves through the catalog. An unknown code is an error, not a silent fallback.

### Admin

A Filament resource under إدارة المطعم. A row whose behavior is `dine_in` shows a link, تصميم الشبكة, to the React designer for that type's file. Changing the behavior away from `dine_in` hides the link but does not delete the file.

### Removing the enum cast

The cast can only go once every consumer reads the catalog. Custom codes cannot exist until it goes, because `OrderType::from('lounge')` throws. So:

- **Phase A keeps the cast.** Strategies resolve from `$order->type->value` through the catalog. Nothing visible changes.
- **Phase B removes it**, in the same change that lets admins create rows. That change updates every consumer listed in *Enum cast consumers* above: Filament badges and exporters use `OrderDisplay`, `OrderBuilder` writes the string, `OrderFactory` uses codes, `Settings.php` builds the KDS target fields from the catalog, and the tests switch to codes.
- `OrderType` is deleted at the end of Phase B. `OrderBehavior` (six keys) replaces it as the only enum.

## 2. Cashier tab layout

The admin can reorder and hide every tab on the cashier desk.

### Storage

One setting, `SettingKey::CASHIER_TABS`, is a JSON list in display order:

```json
[
  { "key": "type:dine_in",      "visible": true },
  { "key": "type:takeaway",     "visible": true },
  { "key": "type:delivery",     "visible": true },
  { "key": "type:talabat",      "visible": true },
  { "key": "type:companies",    "visible": true },
  { "key": "type:web_delivery", "visible": true },
  { "key": "type:web_takeaway", "visible": true },
  { "key": "system:receivables", "visible": true },
  { "key": "system:expenses",   "visible": true },
  { "key": "system:cancelled",  "visible": true },
  { "key": "system:completed",  "visible": true }
]
```

The seed matches today's desk, plus the two companies tabs that Phase C brings back: شركات and تحصيل الآجل. The Phase B seed writes `type:companies` and `system:receivables` as hidden. Phase C flips them to visible. System tab keys are fixed: `receivables`, `expenses`, `cancelled`, `completed`.

### `CashierTabLayout`

`CashierTabLayout::resolve(Shift $shift): array` merges the stored list with the catalog:

- Stored keys keep their position and visibility.
- An active type missing from the list is added at the end, visible. A type the admin just created shows up on the next desk load.
- An inactive type is skipped, but its saved entry is kept, so reactivating it puts it back where it was.
- Unknown keys (a deleted type) are dropped.
- **A hidden tab with open orders in the current shift is still shown**, with a مخفي tag, until those orders close. Otherwise hiding دليفري while a delivery order is open would make that order unreachable, and shift close would refuse to end because of an order the cashier cannot see. Pending web orders count as open.

The result is the `tabs` Inertia prop: `[{ key, kind: 'type'|'system', code?, label, color, icon, presentation?, forcedVisible }]`. Report columns and shift cards use the same order, so the desk and the reports match.

### Admin UI

A تبويبات الكاشير section on the existing Filament Settings page. It is a reorderable `Repeater` with no add or delete. Each row shows the tab label (read-only) and a مرئي toggle. Validation: at least one tab is visible. The section's initial state comes from `CashierTabLayout`, so new types appear in it without a migration.

### Desk behavior

- `Orders/Index.tsx` renders `tabs` in order. Type tabs use `OrderTypeTab`, `system:receivables` uses `ReceivablesTab` (section 4), `system:expenses` uses `ShiftExpensesTab`, and `cancelled` / `completed` use `DisplayTab`.
- The default tab is the first visible one, not `'dine_in'`. A URL hash that points at a hidden or unknown tab falls back to the first visible one. This covers the `#{code}` redirect after completing an order.
- **A hidden type cannot be picked at the desk.** It has no tab and it is left out of the change-type modal. It is still active: its old orders still report, its open orders stay reachable through the مخفي tab, and the website API still accepts the web types. To stop a type everywhere, deactivate it.
- The layout is global. Per-cashier or per-device layouts are out of scope.

## 3. Dynamic tabs

`Orders/Index.tsx` stops filtering `orders` by hardcoded codes. One `OrderTypeTab` replaces `DineInTab`, `DeliveryTab`, `TakeawayTab`, `TalabatTab`, `WebDeliveryTab`, and `WebTakeawayTab`.

`presentation()` decides the body:

- `cards` + `Immediate` — today's takeaway / talabat button: `POST orders.store` with the type code.
- `cards` + `ChooseTable` — today's number modal. Used by dine-in when the floor file is off or has no tables.
- `floor` + `ChooseTable` — the grid. Used by dine-in when that type's file is enabled and has tables.
- `inbox` + `None` — web tabs. No create button. The status filter comes from the strategy (`pending`, `processing`, and `out_for_delivery` for web delivery; `pending` and `processing` for web takeaway).

Validation:

- `createOrder()`: the code exists, is active, and its behavior's `cashierCanCreate()` is true. No `match`.
- `updateOrderType()`: the code exists, is active, is cashier-creatable, and its tab is visible in the layout. `ChangeOrderTypeModal.tsx` gets the same visible types from props, with `OrderDisplay` flags for the table picker, instead of a hardcoded array.

The browser stops computing money. `ManageOrder.tsx` and the payment modal show `orders.sub_total`, `service`, `discount`, and `total` from the server, which already recalculates after every save, plus `guestDue` from `GuestBill` in Phase E. `utils/orderCalculations.ts` loses its service-charge branch and its label map.

## 4. Companies partial payment

A company orders now and pays part, or nothing, at the till. The rest is collected later, in any shift. This is how the old app worked (`payOldOrder` and the companies accounting page in `turbo_resturant`).

### Completion

- `CompaniesBehavior::allowsPartialPayment()` returns `true`. Every other behavior returns `false`.
- When `allowsPartialPayment()` is false, completion refuses to leave a remaining balance: `OrderException('المبلغ المدفوع أقل من إجمالي الطلب')`. Today any type can complete partly paid and the rest is never collected. This closes that gap.
- A companies order with a remaining balance must have a customer linked: the company. Otherwise there is nobody to collect from. Completion refuses with `OrderException('يجب ربط الطلب بعميل قبل الدفع الجزئي')`.
- Completion sets `payment_status` to `partial_paid` or `full_paid`, as `OrderPaymentService::updateOrderPaymentStatus()` already does. Sales and profit count in the shift where the order completed, as today.
- A companies order cannot carry a طلب خارجي (section 6). This keeps seller money out of open company balances.

### Collecting the rest

- `OrderService::collectRemaining(int $orderId, Tenders $tenders): Order` replaces `processOldOrderPayment()`. It locks the order and requires `status = completed`, `payment_status = partial_paid`, and a behavior that allows partial payment. It calls `OrderPaymentService::pay()` with the **current shift**, not the order's shift, so the cash lands in today's drawer. The Phase 0 tender rules apply: one tender may exceed the remaining amount (change), several may not.
- Route: `POST orders.collectRemaining/{order}` on `OrderController`, with the same `shift` and `pos.access` middleware as the other till routes.
- `OrderRepository::partialPaidForTypes(array $codes): Collection` replaces the `Order::where(...)` in `index()`. It loads the customer and payments for orders across all shifts. The codes come from `$catalog->codesWhere(fn ($b) => $b->allowsPartialPayment())`.

### Desk

- The شركات tab is an `OrderTypeTab` with `cards` + `Immediate`, like takeaway. Cards show the customer phone, as `CompaniesTab` does today. `CompaniesTab.tsx` is deleted.
- The تحصيل الآجل tab (`system:receivables`) lists the partly-paid orders from every shift: customer, order number, date, total, paid, remaining. A card opens `PaymentModal` in collect mode, which shows total, paid, and remaining and posts to `orders.collectRemaining`. `ReceiveOrdersPaymentsTab.tsx` is deleted. It reused `DineInTab`, which opened the manage page, and a completed order cannot be modified there.
- Reprinting a partly-paid order's receipt shows المدفوع and المتبقي. These come from the receipt view model, not from the blade.

### Shift report

`ShiftsReportService` sums payments by `payments.shift_id`, not by the shift of the payment's order. This applies to both `calculateShiftStats()` and the period query. For every existing payment the result is the same, because completion writes the order's shift. The only difference is that a later collection counts in the shift that took the money. Without this change, today's drawer would be short by every collection.

### Accounting page

A Filament page, حسابات الشركات, lists each customer with partly-paid orders: their orders, total, paid, and outstanding balance. It is read-only and replaces the old `customersAccounting` page. Collecting money happens at the till, inside a shift, because it is drawer cash.

### Cancelling

Cancelling a partly-paid companies order follows today's `cancelOrder()` rules and deletes its payments. If any of those payments belongs to a closed shift, the cancel is refused, because deleting them would change a closed shift's cash.

## 5. Reports

Stop adding a SQL column per type.

`ProductsSalesReportService` and `CustomersPerformanceReportService` group by `orders.type` (and product or customer). PHP shapes that into `{ code, label, color, quantity, sales, profit }`, in tab-layout order. Widgets and exporters build their columns from that payload:

- Include every active type.
- Also include an inactive type if the filtered period has sales for it.
- Drop a column only when the type is inactive and the period total is zero.
- Tab visibility does not affect reports.

`ShiftsReportService::calculateOrderTypeStats()` returns the same list shape. `CurrentShiftDoneOrdersStats` and `PeriodShiftDoneOrdersStats` loop over it to build cards. Delete the `dineIn` / `talabat` key map.

`SUM(orders.total)` and `SUM(orders.profit)` stay the restaurant figures. طلب خارجي money is not on the order, so these sums need no exclusion clause.

`ChannelPerformanceReportService` keeps its hardcoded 15% / 25% channel rates. A `commission_rate` column is out of scope.

## 6. طلب خارجي (`ExternalOrder`)

The sauce car sells to the guest, prints on the restaurant receipt, and is not restaurant revenue. The cashier works on one screen.

### Why it is not on `order_items`

`OrderPaymentService` limits payments to `order.total`. If `total` stays restaurant-only, the guest cannot pay for the sauce. If `total` becomes the guest total, every sales query counts the sauce until it is rewritten. `OrderCalculationService` is the invariant those queries trust.

### Tables

- `external_sellers` — `name`, `phone`, `is_active`. The sauce car is a seller, not a stock supplier. Do not reuse `suppliers`.
- `external_orders` — one per seller per host order. Columns: `host_order_id`, `external_seller_id`, `seller_name` (snapshot), `status` (`pending`, `fulfilled`, `cancelled`), `total`. Unique `(host_order_id, external_seller_id)`.
- `external_order_items` — `name`, `quantity`, `price`, `total`. No `product_id`, no `cost`. These lines are not stock.
- `external_order_collections` — `external_order_id`, `shift_id`, `method`, `amount`, `user_id`. One row per collection, like `payments`. This is what the drawer needs: a single `collected_amount` counter cannot say whether the money came in as cash or card, or in which shift.
- `external_seller_settlements` — `external_seller_id`, `shift_id`, `amount`, `user_id`, `note`. Cash leaving the drawer. Not an expense and not a contra-sale. A negative row reverses a settlement.

Derived amounts (no stored counters):

- Collected for a bill = `SUM(collections.amount)`.
- Payable to a seller = `SUM(collections) - SUM(settlements)`. Do not owe the car money the guest has not paid.

### Till

On the manage-order screen, إضافة طلب خارجي picks a seller and adds lines to that seller's `ExternalOrder`. `ExternalOrderController` → `ExternalOrderService`, which uses the same `locked()` rule on the host order, so a sauce line cannot be added while the order is being completed.

`GuestBill` is the only new coordinator. It is a calculator; it does not store a bill.

- `due(Order)` = restaurant remaining (`order.total - paid`) + Σ(external total − collected).
- `split(Order, Tenders)` returns the restaurant `Tenders` plus a collection per seller:
  1. **Amounts.** The restaurant remainder is filled first, then sellers in a stable order (by `external_orders.id`).
  2. **Methods.** Seller shares are taken from cash first, then card, then talabat card. The restaurant gets whatever tender is left. The sauce car is paid in cash, so this keeps its money in the drawer where the payable expects it.
  3. **Change.** The single-tender rule from Phase 0 applies to the guest due: one tender may exceed it (change), several may not.
- `OrderCompletionService` calls `GuestBill::split()`, then `OrderPaymentService::pay()` with the restaurant tenders, then writes the collection rows. It does not know about sellers itself.

**The full guest due must be paid.** Section 4 already requires full payment for every behavior except companies, and `ExternalOrderService` refuses a طلب خارجي on a behavior that allows partial payment. So a host order with a sauce bill can never complete with that bill unpaid.

A host order with restaurant total 0 can complete when the external side is paid. Stock removal runs on restaurant items only, so it does nothing when there are none.

**Cancelling the host** cancels its external orders. If money was collected for them, the collection rows are deleted with the payments (the same as today's `cancelOrder()`). A seller that has already been settled for that money gets a negative settlement row. There is no refund engine.

**Returns** (`OrderReturnService`) cover restaurant items only. Returning a طلب خارجي is out of scope.

### Receipt blades

The printer does not use the React preview. `PrintService::printOrderReceipt()` renders Blade:

- 5 items or fewer: `receipt-template.blade.php`.
- More than 5: `receipt-header.blade.php` (meta + first 5), then `receipt-items.blade.php` chunks, then `receipt-footer.blade.php` (totals).

`PrintService` loads `externalOrders.items` with the order, builds one receipt view model, and passes it to the header, template, and footer. The blades must not add up money themselves. The view model contains:

- `OrderDisplay`, so the label, table line, customer block, and driver block are not hardcoded. Delete `$getOrderTypeString` and the `OrderType::DINE_IN` / `in_array(..., ['delivery', 'web_delivery'])` checks in both blades. The kitchen blades get the label from `OrderDisplay` and nothing else. They do not print طلب خارجي lines.
- Restaurant lines, then the restaurant total (`order.total`).
- A block per seller, titled طلب خارجي — {seller name}.
- إجمالي الطلبات الخارجية.
- المطلوب من العميل (`GuestBill::due` before payment, or the guest total after).

`receipt-items.blade.php` stays a restaurant-item chunk. On a split print, the seller blocks and the guest total are on the footer. On a single print, they are at the bottom of the template, and `ceil($order->total)` is no longer labelled as the amount the guest pays.

`ReceiptTemplate.tsx` and `PartialReceiptTemplate.tsx` receive the same view model as props. They do not redo the split. The guest total is never written back to `orders.total`.

### Shift drawer

Sales queries do not change. The shift report (`ShiftsReportService`, `CurrentShiftMoneyInfoStats`, `PeriodShiftMoneyInfoStats`) gains two lines: external cash collected this shift and external settlements paid this shift. Available cash becomes `start_cash + cash payments + external cash collected - settlements - expenses`. Without these lines the drawer looks over by the sauce-car money. `ShiftService::endShift()`, `end_cash`, and `has_deficit` are legacy and are not changed.

**Settlement** is a Filament page: outstanding amount per seller, record a payout. Recording a payout needs an open shift (it is cash leaving that drawer). With no open shift, the page refuses. A payout cannot exceed the payable.

### Sync

Nest external orders, items, and collections inside the existing order document in `OrderBuilder`, with the seller name snapshot. Do not change `total` or `profit` in that payload. Before Phase B ships, check that the sync receiver accepts type codes it has not seen before. `order_types`, the tab layout, and floor files stay local unless branch sync of backoffice config is already required. A floor file is not an outbox row.

## 7. Floor grid (dine-in presentation)

### Rule

`DineInBehavior::presentation()` is the only strategy that can return `floor`. It asks `FloorLayoutService` whether `storage/app/floor-layouts/{code}.json` exists, has `enabled: true`, and has at least one table. Creating لاونج with behavior `dine_in` creates no file. The designer creates it on first save. Until then, the tab shows the classic cards.

No other behavior reads that directory.

### JSON file, not grid columns

```json
{
  "version": 1,
  "enabled": false,
  "columns": 8,
  "rows": 6,
  "sections": [
    { "name": "داخلي", "zone": "indoor", "color": "#e8f5e9", "col": 0, "row": 0, "colSpan": 4, "rowSpan": 6 }
  ],
  "tables": [
    { "number": "صالة - 1", "capacity": 4, "zone": "indoor", "active": true, "col": 0, "row": 0, "colSpan": 1, "rowSpan": 1 }
  ]
}
```

- `version` lets a later designer field be read without breaking an older file. Unknown keys are kept on save, not stripped.
- `FloorLayoutService` validates on save: table numbers are unique in the file, and cells are inside the grid. It writes to a temp file and renames it, so a crash cannot leave half a file.
- Include `storage/app/floor-layouts` in the server backup. The app runs on one box (`/var/www/turbo_restaurant/larament`), so a file is enough.

**Occupancy stays in `dine_tables`.** That is live state, and it has to lock with the order.

- Add `order_type_code` (a string, the same reference style as `orders.type`) so لاونج table 4 does not collide with صالة table 4.
- The migration drops the existing unique index on `table_number`, backfills `dine_in`, and adds unique `(order_type_code, table_number)`.
- Do not add `col`, `row`, `capacity`, or `zone` columns.
- The POS joins the file to `dine_tables` on `table_number`.

`table_number` in the file is the same string stored in `orders.dine_table_number`. Renaming it in the designer would detach open orders, so the designer does not offer rename. Moving a table changes `col` / `row` in the file only.

`TableManagementService` reserves and frees by `(order_type_code, table_number)`. `DineInBehavior` is the only caller. A table that is occupied in `dine_tables` but missing from the file still frees correctly.

`table_types` (the name prefix in the classic modal) stays for the fallback picker. It is not a floor zone.

### Designer

- Page: `resources/js/Pages/Floor/Designer.tsx` (Inertia + Ant Design).
- Controller: `FloorLayoutController`. Route: authenticated, outside the Filament panel, with the same permission as the order-type resource.
- A CSS grid. Click a cell to place or select a table. Ant Design inputs for number, capacity, zone, and span. Sections are colored rectangles behind the cells. Save posts the whole document.
- The seeded صالة file is written with `enabled: false`, so the POS does not change until someone turns it on.

### POS

When `presentation()` is `floor`:

- A vacant active cell creates a dine-in order with that `table_number`.
- An occupied cell opens the order and shows the existing timer.
- طاولة خارج المخطط keeps the classic modal for a number that is not in the file.

When `presentation()` is `cards`, `OrderTypeTab` renders today's dine-in body. There is no second dine-in implementation.

Live occupancy uses the existing `OrderCreated`, `OrderCompleted`, and `OrderCancelled` broadcasts. `changeOrderType()` fires none of them today, so moving an order between tables or types would leave the floor stale. Add an `OrderTypeChanged` broadcast in the same channel. The floor refetches on all four.

## Phases

Tests for each phase come first. Existing till tests stay green through Phase 0 and Phase A.

### Phase 0 — ordering refactor

Everything in *Phase 0 — ordering refactor* above. The till looks the same. Locking, the customer recalculation, order numbering, and change tracking are fixed.

### Phase A — catalog and strategies, no visible change

- Migration, seeder, catalog, resolver, six strategies, `OrderDisplay`.
- Wire open, complete, cancel, change-type, service charge, and `recalculatesTotals`.
- Move `ShiftService` and `getNextOrderNumber()` to catalog codes.
- **Keep the enum cast.** Resolve through `$order->type->value`.
- Tests: existing tests stay green. New: dine-in reserves from `OrderOpening` and counter does not, resolved from the catalog row. A web order completes without its totals being recalculated.


### Phase B — dynamic tabs, tab layout, cast removal

- Filament order-type resource (the designer link shows for `dine_in`; the designer itself comes in Phase F). Behavior lock.
- `CASHIER_TABS` setting, `CashierTabLayout`, the Settings section, and the generic desk. `type:companies` and `system:receivables` are seeded hidden until Phase C.
- Controller and API validation against the catalog. `ChangeOrderTypeModal` from props.
- Remove the enum cast and update every consumer. Delete `OrderType`.
- `OrderDisplay` everywhere labels or blocks were hardcoded: blades, receipt TSX, `ManageOrder.tsx`, `orderHeader.ts`, KDS payload, Filament badges and exporters. The browser stops computing the service charge.
- Tests:
  - An admin-created `catering` row with `counter` behavior gets a tab at the end of the desk and can create an order.
  - A `lounge` row with `dine_in` behavior requires a table and prints the table line without a blade change.
  - A web behavior cannot be created from the cashier button and cannot be assigned to a custom row.
  - Reordering the tabs changes the desk order. A hidden tab is not rendered. A hidden tab with an open order is rendered as مخفي. A hash to a hidden tab falls back to the first visible one.
  - A hidden type is not in the change-type options, and `updateOrderType()` rejects it.
  - A behavior change is refused while the type has open orders.
  - The KDS target settings show a field for `catering`.

### Phase C — companies partial payment

- `allowsPartialPayment()` enforced at completion. Customer required for a partial companies order.
- `collectRemaining()`, its route, `OrderRepository::partialPaidForTypes()`.
- The شركات and تحصيل الآجل tabs (flip them to visible), `PaymentModal` collect mode, and المدفوع / المتبقي on the receipt.
- `ShiftsReportService` sums payments by `payments.shift_id`.
- The حسابات الشركات Filament page.
- Delete `CompaniesTab.tsx`, `ReceiveOrdersPaymentsTab.tsx`, and the controller's direct `Order::where`.
- Tests:
  - A takeaway order paid 60 of 100 cannot complete.
  - A companies order with a customer, paid 60 of 100, completes as `partial_paid`. Its sales (100) count in shift 1.
  - In shift 2, collecting 40 cash makes it `full_paid` and removes it from the تحصيل الآجل list. Shift 2's cash payments include the 40. Shift 1's do not.
  - Collecting more than the remaining amount with several tenders throws. One cash tender over it is capped.
  - A companies order with no customer cannot complete partly paid.
  - Collecting on an order that is not `partial_paid`, or not a companies behavior, is refused.
  - Cancelling a partly-paid order whose collection is in a closed shift is refused.
  - For orders without later collections, every existing shift report test gives the same numbers.

### Phase D — dynamic report columns

- Group-by queries, dynamic widget columns, dynamic export columns, dynamic shift cards, all in tab-layout order.
- Test: a completed `catering` order appears as its own column and card. Deactivating the type removes it from the desk, but its column stays for a period that contains its sales. Hiding its tab does not affect reports.

### Phase E — طلب خارجي

- Sellers, `ExternalOrder`, collections, settlements, `ExternalOrderController`, the manage-order UI, `GuestBill`.
- The blades, the two receipt React components, the payables page, the shift report lines, and sync nesting.
- Tests:
  - 100 restaurant + 40 sauce: guest due 140, `orders.total` 100, profit excludes the sauce. The full receipt and the footer both show 140 as المطلوب من العميل and 100 as the restaurant total.
  - A partial payment of 80 is refused.
  - Adding a طلب خارجي to a companies order is refused.
  - Paying 100 card + 40 cash records 40 cash collected for the seller. Paying 150 cash alone records 40 for the seller and 100 for the restaurant; the 10 change is recorded nowhere.
  - The shift report's available cash includes the 40. A settlement of 40 reduces available cash and the payable, and does not reduce sales. A settlement with no open shift is refused.
  - Stock does not move for the sauce line. `OrderCalculationService` output is unchanged.

### Phase F — floor file and React designer

- `FloorLayoutService`, `Floor/Designer.tsx`, `FloorLayoutController`, `dine_tables.order_type_code`, `OrderTypeChanged` broadcast.
- Tests: a missing file or `enabled: false` keeps the classic modal. An enabled file with a vacant cell creates an order for that `table_number`. A counter type has no designer link. A second dine-in type does not occupy the first type's table 4. Adding a key to the JSON needs no migration and survives a save. A duplicate table number is rejected on save.

## Acceptance

- The till behaves the same after Phase 0, except for the bug fixes listed there.
- Admin adds كيتيرنج with counter behavior, a label, and a color. The cashier desk shows the tab at the end on the next load. The admin drags it to second place and the desk follows. A completed order is its own sales column and shift card.
- Admin hides تيك أواي. The tab disappears and تيك أواي is no longer offered when changing an order's type. If a takeaway order is still open, the tab shows as مخفي until that order closes.
- A cashier opens a شركات order for a company customer, takes 60 of 100, and completes it. The next day another cashier finds it in تحصيل الآجل and collects 40 cash, and that 40 is in that day's drawer. حسابات الشركات shows the company's balance drop to 0.
- A takeaway order cannot be completed partly paid.
- Admin adds لاونج with dine-in behavior. تصميم الشبكة opens the React page and writes `storage/app/floor-layouts/lounge.json`. Until `enabled` is true, the tab uses the classic table modal. When it is enabled, the grid matches the file, occupied cells show a timer, and completing the order frees the cell.
- Deactivating a type removes its tab and its create option. Old orders still show its label in reports for periods that contain them.
- A dine-in order with restaurant items and a sauce-car طلب خارجي prints one receipt, including on the long-receipt footer path. Sales, profit, and product reports exclude the sauce. The shift report shows the amount held for that seller. Settling it reduces drawer cash and the payable, and does not reduce sales.
- `orders.total` for that order is the restaurant total only.
- The word إسناد does not appear in the UI.

## Settled

- **Hidden tabs are not offered in the change-type modal.** Hiding a type removes it from the desk entirely: no tab and no change-type option. Deactivating it also removes it from the website API and creation everywhere.
- **Companies partial payment comes back** as `CompaniesBehavior`, the تحصيل الآجل tab, and the حسابات الشركات page (Phase C).
- **`endShift()`, `end_cash`, and `has_deficit` are legacy.** Nothing depends on them, so this work leaves them alone. The drawer figure is the shift report.

## Out of scope

- Pixel-freeform canvas and drag physics.
- Per-type tax or service rates, a cut kept by the restaurant, automatic payouts.
- Replacing the 15% / 25% channel rates.
- Per-cashier or per-device tab layouts.
- Credit limits, statements, or aging for companies. Collecting company balances outside a shift.
- Fixing or removing the legacy `endShift()` cash calculation.
- Extracting customer, driver, or print methods out of `OrderController`.
- Stopping `OrderItemRepository` from deleting and recreating lines on save.
- A stored bill document above `Order`. `GuestBill` is a calculator.
- Cloning `Order` onto `ExternalOrder` (type, profit, kitchen, stock, its own payment rows).
- Returning or refunding a طلب خارجي.
- A `floor_layouts` table, or grid coordinates as database columns.
