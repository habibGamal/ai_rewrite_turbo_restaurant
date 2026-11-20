# Money: charges, returns, shifts

This page explains how every money feature reaches the shift's **expected cash** and the reports.

## Shift cash formula

The expected drawer cash («النقدية المتاحة») is computed by `ShiftsReportService::calculateShiftStats()['availableCash']`:

```
opening cash
+ cash payments received in this shift
+ cash revenues
+ external cash
− external seller settlements
− expenses
− cash refunds paid in this shift
```

Payments count in the shift that **received** them, so a pay-later order adds only what was paid, and a later collection adds to the collecting shift.

At shift end (`ShiftService::endShift`):

- `end_cash` = expected cash, `real_cash` = counted cash.
- `losses_amount = end_cash − real_cash` (positive = shortage), `has_deficit` when positive.
- Ending is refused while orders are still in progress (web orders may carry over when `allow_web_orders_shift_transfer` is on).
- Held instant-sale carts are deleted.

## Custom fees

Free-text name + amount on any order type except instant sale (`OptionalFeature::CUSTOM_FEES`).

- Table `order_fees` (`name`, `name_key` for grouping, `amount`, `user_id`).
- `OrderFeeService::add / remove / suggestions` (most-used names first). Removing is audited.
- Updates `orders.fees_total`, recalculates totals, dispatches `OrderChargesChanged::FEES`.
- Permissions: `order.add_fees` (cashier), `order.remove_fees` (admin), `view_custom_fees_report`.

## Equipment

Flat price per use plus a cost (`OptionalFeature::EQUIPMENT`). Typical use: shisha, PlayStation, a rented tool.

- Tables `equipment`, `equipment_order_type` (which order types may use it), `order_equipment` (snapshot of name, unit price and cost per order line).
- `OrderEquipmentService::sync(orderId, [equipment_id => qty])`, `removeLine()`, `allowedFor($order)`.
- Lowering or removing needs `order.remove_equipment` and is audited.
- `equipment_total` adds to the order total; `equipment_cost` lowers its profit.

## Guest count

`order_types.guests_mode`: `off`, `optional` or `required` (`App\Enums\GuestsMode`). Never asked on instant or web types.

- Asked when the order opens; editable from the order screen.
- Required mode blocks payment until a count is entered.
- Reports use `App\Core\Orders\GuestStats` (only orders with a count): guests, sales of those orders, average per guest.

## Returns

`OrderReturnService` handles returns, supported by `ReturnRefundCalculator`. Behind `OptionalFeature::RETURNS` (off on a fresh install). Returns happen **only from the POS**: the admin create page and `ReturnOrderAction` were removed, and «مرتجعات الطلبات» is a read-only list.

- **Proportional refund calculation**: a refunded line receives what the customer actually paid for it. An item discount stays on its line; order-level discounts are apportioned proportionally across lines based on post-item-discount value; dine-in service charge and taxes are refunded by proportional share; flat delivery fees are excluded; total rounding is shared. Partial quantities round to 0.01 with the final unit absorbing remainder.
- `OrderReturnService` caps line refunds at this calculated maximum and populates it automatically. The POS return screen displays the full calculation breakdown.
- Receipts print `#<order id>` as a Code128 barcode. Scanning it finds the order; bare digits search the current shift then older shifts (within `returns_max_days`).
- Each line has **restocked**: `true` returns the quantity to stock, `false` records it as damaged with no stock move.
- Only a **completed, fully paid** order can be returned (the order row is locked first), so a deferred order can't refund cash never collected. An order with returns can't be cancelled.
- Fee and equipment lines can be refunded too by `OrderReturnService` (`order_fee_id` / `order_equipment_id` on `order_return_items`).
- An exchange is a return plus a new instant sale in the same transaction; the refund is first applied as a credit tender. A card exchange sends the exact difference; split tenders above the total give the extra back from cash; totals compare at 2 decimals. The exchange order's receipt is printed.
- Refunds count in the shift that **paid** them (shift reports) or on the refund date (date reports). Current and period shift reports feature a dedicated returns table.
- Permissions: `order.return`, `order.return_without_restock`, `order.return_any_age`, `view_returns_report`.
- Report helpers: `App\Services\Reports\ReturnsAggregates`.

## Multiple terminals & drawer sessions

When `OptionalFeature::MULTI_TERMINAL` is enabled, shifts contain independent drawer sessions (`terminal_sessions` table):

- **Architecture**: A shift starts when the first drawer opens. Subsequent drawers register under the same open shift with their own opening cash. One cashier operates one drawer session at a time.
- **Handovers**: A newcomer can take over an open drawer session without closing the shift; `terminal_session_handovers` records `from_user_id`, `to_user_id`, `notes`, and timestamps.
- **Financial breakdown**: `TerminalSessionService::breakdown()` computes cash by kind per drawer (opening cash, cash sales, cash revenues/in, expenses/out, expected cash).
- **Scoping**: Payments, expenses, revenues, returns, external collections, and seller settlements are stamped with `terminal_session_id`. Orders are stamped with `terminal_id`.
- **Closure**: A cashier closes their own drawer (`terminal.close`) recording expected cash. Managers can close another's drawer (`terminal.close_others`) with a mandatory reason. The shift ends once all drawers are closed.

### Sales point separation («فصل منافذ البيع»)

`OptionalFeature::TERMINAL_SEPARATION` (`feature_terminal_separation_enabled`), vendor only, effective only while multiple terminals are on (`FeatureService::terminalSeparationEnabled()`). **Separated sales points behave like separate branches of the same brand on one system.**

- **Stock**: `terminals.warehouse_id` is the sales point's issue warehouse. `App\Services\StockWarehouseResolver` picks the warehouse of every sold line: separated → `Terminal::issueWarehouseId()` of `orders.terminal_id` (stopped or missing warehouse → default warehouse); not separated, or an order with no terminal → `Product::stockWarehouseId()` (product → main category → default). Manufactured components follow the sold item; returns go back to the original sale movement's warehouse, so changing the mode never moves old stock. POS variant stock uses `forCurrentDevice()`.
- **Order ownership**: `TerminalSeparation::canAct()` allows an order only from the device bound to `orders.terminal_id` (no bound device, e.g. the admin panel, is never limited). `EnsureOrderTabAccess` (`order.tab`, on every POS route bound to `{order}`, web orders, delivery, external sellers and print) answers 403 with the owning sales point's name; the desk (`OrderController::index`) hides other sales points' orders and receivables; quick returns refuse them in lookup and store.
- **Web orders**: `SettingKey::WEB_ORDERS_TERMINAL_ID` (vendor console, empty = default terminal). `TerminalSeparation::assignWebOrder()` stamps it on the new web order and points the resolver at that terminal's open session of the shift, so web payments land in its drawer.

## Revenues and expenses

- Revenues (`OptionalFeature::REVENUES`): `revenue_types` and `revenues` (`amount`, `method` cash|card, `notes`, per shift). Admin only; the cashier never sees them.
- Expenses: the cashier adds shift expenses from the POS (`pos.tab.system.expenses`); the admin `ExpenseResource` is read-only.
- **Shift money page** (`/admin/shift-money`, «مصروفات وإيرادات الورديات»): pick any shift and add, edit or delete its expenses and revenues through `ShiftMoneyService`.
  - Counted cash and shortage inputs are removed (physical counting belongs to shift closing/reports).
  - With multiple terminals active, adding or editing an expense or revenue requires selecting which drawer of that shift it belongs to.
  - POS expenses (`ExpenseController`): each drawer lists its own; only the drawer that recorded an expense can edit or delete it, and only while that drawer is open.
  - Needs `shift_money.manage`; closed shifts also need `shift_money.edit_closed` (gated by `OptionalFeature::CLOSED_SHIFT_EDITS`, off by default; `ShiftMoneyService` refuses closed-shift changes while it is off) and a written reason.
  - Editing a closed shift recomputes `end_cash` (and closed drawer expected cash), `losses_amount` and `has_deficit` (the counted `real_cash` is untouched) and stamps `money_edited_at` / `money_edited_count`.

## Audit log

`App\Core\Audit\AuditLogger` writes append-only rows to `audit_logs` (the model throws on update or delete):

```php
AuditLogger::record($model, AuditAction::Updated, $old, $new, reason: '...', context: 'shift_money');
AuditLogger::updated($model, $originalTakenBeforeSave);
```

Contexts in use: `shift_money`, `pos_expense`, `order_fee`, `equipment_removed`. Admin screen: «سجل التعديلات» (`view_audit_logs`). Audit rows are also synced to the platform.

## Reports helpers

| Helper | Use |
|---|---|
| `App\Core\Orders\SalesBreakdown` | Items / fees / equipment split of sales for a query or order. |
| `App\Core\Orders\GuestStats` | Guest counts and average per guest. |
| `App\Services\Reports\ReturnsAggregates` | Refunds per shift, period or grouping. |
| `App\Services\Reports\ReportTerminalScope` | Sales point tab of the current-shift / shift-period reports (scoped per request). Widgets call `ScopesReportToTerminal::reportTerminal()` (reads `pageFilters['terminal']`); `ShiftsReportService`, `ReturnsAggregates::forShifts()`, `GuestAverageStats` and `EquipmentReportService` narrow orders by `orders.terminal_id` and money rows by `terminal_session_id`; opening cash = the sales point's drawer sessions. Tabs (`ReportTerminalTabs`) show only with 2+ sales points in scope. Exports stay whole-shift. |
| `ExpensesRevenuesReportService` | Expenses, revenues and the computed «إيرادات الطلبات» line. |
| `CustomFeesReportService`, `EquipmentReportService` | Fee and equipment reports, net of refunds. |

::: warning Check new reports for returns
Sum net sales from `orders.total` minus refunds, not from `order_items.total` (which is before discounts), and include returns.
:::
