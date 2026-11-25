# Platform sync

Each install can be **paired** with the central sync/reporting platform, which collects documents from all shops for owner dashboards. This is a different system from the ordering website.

## Pairing

Admin page «ربط المنصة» (`PlatformConnectionPage`): enter «رابط المنصة» and the «كود الربط» issued by the platform, then «ربط». The POS calls `POST {platform}/api/v1/pair` and stores the returned token in `platform_connections`. «فك الربط» unpairs.

## Outbox

- `App\Sync\SyncOutboxObserver` and explicit `Outbox::record(DocumentType, uuid)` calls write outbox rows inside the business transaction.
- `platform:sync` (every minute) builds batches with `BuilderRegistry`, gzips them and posts them to `{platform}/api/v1/ingest/batches` with `Authorization: Bearer <token>`, `Idempotency-Key: <batch uuid>`, `X-Schema-Version: 1` and `X-Pos-Version`.
- `platform:heartbeat` reports health every five minutes; `platform:prune` deletes sent rows older than 30 days.
- The page offers «مزامنة الآن», «إعادة محاولة الفاشل» and «إعادة إرسال فترة».

## Document types

`App\Sync\DocumentType`: `order`, `order_return`, `shift`, `expense`, `revenue`, `revenue_type`, `purchase_invoice`, `purchase_return`, `waste`, `stocktaking`, `inventory_day`, `day_summary`, `product_group`, `product`, `recipe`, `category`, `supplier`, `expense_type`, `staff`, `audit_log`.

The day summary carries totals including `refunds_total`, `refunds_cash`, `fees_total`, `equipment_total`, `revenues_total`, `guests_total`. When a closed shift's money is edited, its day summary is queued again.

### Drawer sessions in sync payload

To support multiple terminals without breaking older platform versions, drawer data is added additively:
- **Shift payload**: includes `terminal_sessions` list with `uuid`, `terminal_name`, `is_default`, cashier info, closing user, `opening_cash`, `expected_cash`, open/close timestamps, `close_note`, and nested `handovers` (`from`, `to`, `note`, `at`).
- **Shift re-sync triggers**: opening a drawer, closing a drawer, or executing a cashier handover automatically queues a re-sync of the parent shift document.
- **Related documents**: `expense`, `revenue`, and `order_return` documents carry `terminal_session_uuid`; `order` documents carry `terminal_name`.

::: warning Adding a field
The receiving platform is not in this repository. A new document type or payload field also needs matching handling there.
:::

## Runbook

### Historical shift date recovery

On MariaDB with implicit `TIMESTAMP` defaults enabled, the original `shifts.start_at` column could acquire `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`. Updating UUIDs or `business_date` on historical shifts then overwrote `start_at`, and the backfill copied that overwritten date to the platform.

Migration `2026_10_31_235959_preserve_shift_start_at` removes `ON UPDATE` while keeping existing values. Its rollback keeps the safe definition. To repair shifts whose `start_at` was already overwritten, preview first on the POS:

```bash
php artisan migrate --force
php artisan platform:repair-shift-dates
```

Verify from the table that historical `created_at` matches the real shift opening time, then apply and sync:

```bash
php artisan platform:repair-shift-dates --apply --from-created-at
php artisan platform:sync --max-seconds=120
```

How `platform:repair-shift-dates` (`RepairShiftDatesCommand`) works:

- **Candidate selection:** selects closed shifts where `DATE(start_at) > DATE(end_at)` and `created_at <= end_at`, plus open shifts where `DATE(created_at) < DATE(start_at)` and orders exist with `orders.created_at >= shifts.created_at` and `DATE(orders.created_at) < DATE(shifts.start_at)`. Normal shifts and valid post-midnight shifts are left untouched.
- **Backup before write:** saves the original shift rows under the `local` disk at `repairs/shift-dates/<uuid>.json` before modifying any row; aborts if writing the backup fails.
- **Transactional repair and requeue:** restores `start_at` and `business_date` from `created_at` in one transaction, queues new outbox rows for the shift and its orders, returns and expenses, and when paired queues `day_summary` for both the old and corrected dates. Repeat runs are idempotent.
- **Platform workers:** keep the platform ingest/aggregate workers and scheduler running so higher document versions update the warehouse rows and mark both old and corrected business dates dirty.
