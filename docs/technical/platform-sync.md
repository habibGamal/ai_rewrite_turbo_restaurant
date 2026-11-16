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

::: warning Adding a field
The receiving platform is not in this repository. A new document type or payload field also needs matching handling there.
:::
