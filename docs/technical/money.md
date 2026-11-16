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

`OrderReturnService` handles cashier quick returns and admin returns.

- Receipts print `#<order id>` as a Code128 barcode. Scanning it finds the order; bare digits search the current shift then older shifts (within `returns_max_days`).
- Each line has **restocked**: `true` returns the quantity to stock, `false` records it as damaged with no stock move.
- Fee and equipment lines can be refunded too (`order_fee_id` / `order_equipment_id` on `order_return_items`).
- An exchange is a return plus a new instant sale in the same transaction; the refund is first applied as a credit tender.
- Refunds count in the shift that **paid** them (shift reports) or on the refund date (date reports).
- Permissions: `order.return`, `order.return_without_restock`, `order.return_any_age`, `view_returns_report`.
- Report helpers: `App\Services\Reports\ReturnsAggregates`.

## Revenues and expenses

- Revenues (`OptionalFeature::REVENUES`): `revenue_types` and `revenues` (`amount`, `method` cash|card, `notes`, per shift). Admin only; the cashier never sees them.
- Expenses: the cashier adds shift expenses from the POS (`pos.tab.system.expenses`); the admin `ExpenseResource` is read-only.
- **Shift money page** (`/admin/shift-money`, «مصروفات وإيرادات الورديات»): pick any shift and add, edit or delete its expenses and revenues through `ShiftMoneyService`.
  - Needs `shift_money.manage`; closed shifts also need `shift_money.edit_closed` and a written reason.
  - Editing a closed shift recomputes `end_cash`, `losses_amount` and `has_deficit` (the counted `real_cash` is untouched) and stamps `money_edited_at` / `money_edited_count`.

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
| `ExpensesRevenuesReportService` | Expenses, revenues and the computed «إيرادات الطلبات» line. |
| `CustomFeesReportService`, `EquipmentReportService` | Fee and equipment reports, net of refunds. |

::: warning Check new reports for returns
Sum net sales from `orders.total` minus refunds, not from `order_items.total` (which is before discounts), and include returns.
:::
