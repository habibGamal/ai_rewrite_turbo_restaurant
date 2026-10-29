# Platform Connector (POS → Platform Restaurants) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a larament POS pair with the central reporting platform and push every business document (orders, returns, shifts, expenses, purchases, waste, stocktaking, closed days, catalog) reliably, including after days offline.

**Architecture:** A transactional outbox (`sync_outbox`) records *references* to changed documents inside the same DB transaction as the business change (model observers + explicit calls at day close). A scheduled `platform:sync` command coalesces pending rows per document, builds the current snapshot through one builder class per document type, and POSTs gzip batches with an idempotency key. A `platform:heartbeat` command reports health and receives commands (resync, rotate token). The POS never opens an inbound port.

**Tech Stack:** Laravel 11 · FilamentPHP v4 · Pest · SQLite (tests) / MySQL (prod) · `Http` client · ramsey/uuid (via Laravel).

**Spec:** `D:\platform-restaurants\.plans\00-architecture.md` (§4 ingestion contract, §4.3 document types, §4.4 heartbeat, §4.5 pairing). Read it first — payload field names below must match it exactly.

## Global Constraints

- Run every command from `D:\ai_rewrite_turbo_restaurant\larament` (AGENTS.md shows an old `e:\AI_rewirte` path — use the real one).
- Quality gate before each commit: `./vendor/bin/pint` then the task's tests; `composer review` at the end.
- Never run `php artisan serve`.
- Arabic UI strings; add new keys to `lang/ar.json`.
- Money in payloads: decimal **string**, 2 places (`"400.00"`); quantities: string, 3 places (`"1.500"`). Never floats.
- Timestamps in payloads: ISO-8601 with offset (`2026-10-29T21:02:55+03:00`), timezone `Africa/Cairo`.
- `schema_version` = `1`. Batch max 500 documents. Endpoint paths: `POST {url}/api/v1/pair`, `POST {url}/api/v1/ingest/batches`, `POST {url}/api/v1/heartbeat`.
- Backoff sequence (minutes): `1, 2, 5, 15, 30, 60` then stays at 60. Row moves to *failed* after 10 attempts.
- When no active platform connection exists, the connector does **nothing** (no outbox rows, no HTTP) — existing behaviour and tests must be unaffected.
- Never transfer: passwords, sessions, settings, printers, dine tables, customer name/phone/address. Customer → `customer_hash = sha256(phone . branch_secret)`.

## Review Focus

1. **Bulk query-builder updates bypass observers** — e.g. `ShiftService.php:78` moves open web orders with `->update(['shift_id' => …])`. Expected: any final order (completed/cancelled) whose data changes through a bulk update still reaches the outbox. Task 5 includes a grep step and a test per hit.
2. **`Event::fake()` disables model observers** in existing tests — expected: connector tests never call `Event::fake()` without arguments; use `Event::fake([SpecificEvent::class])`. Task 5 tests assert observers fire.
3. **Business day past midnight** — a shift started on the 29th at 23:00 and orders at 00:30 on the 30th must all carry `business_date = 2026-10-29`. Task 2 test.
4. **Same document changed many times between sends** — expected: one document in the batch with the highest outbox id as `version`, all its rows marked sent. Task 8 test.
5. **Network dies mid-send / platform returns 5xx after receiving** — expected: retry reuses the same `batch_uuid` so the platform answers 409 and nothing is duplicated. Task 8 tests.

---

## File Structure

```
app/Sync/
  Outbox.php                         record() — the only writer of sync_outbox
  DocumentType.php                   enum of doc types (string values = platform "type")
  Payload.php                        money()/qty()/ts() formatting helpers
  SyncOutboxObserver.php             one observer, per-model rules
  Builders/DocumentBuilder.php       interface
  Builders/{Order,OrderReturn,Shift,Expense,Purchase,Waste,Stocktaking,InventoryDay,DaySummary,
            Product,Recipe,Category,Supplier,ExpenseType,Staff}Builder.php
  BuilderRegistry.php                DocumentType → builder
  BatchSender.php                    pick → coalesce → build → send → mark
  PlatformClient.php                 HTTP calls (pair, sendBatch, heartbeat)
  PairingService.php
  BackfillService.php
  HeartbeatService.php
app/Models/PlatformConnection.php    single-row connection (encrypted token)
app/Models/SyncOutbox.php, SyncBatch.php
app/Models/Concerns/HasSyncUuid.php
app/Enums/ExpenseCategory.php
app/Console/Commands/PlatformSyncCommand.php        platform:sync
app/Console/Commands/PlatformHeartbeatCommand.php   platform:heartbeat
app/Console/Commands/PlatformBackfillCommand.php    platform:backfill
app/Console/Commands/PlatformPruneCommand.php       platform:prune
app/Filament/Pages/PlatformConnectionPage.php       ربط المنصة
database/migrations/2026_11_01_00000{1..6}_*.php
tests/Feature/Sync/*Test.php
```

---

### Task 1: Stable UUIDs on synced records

**Files:**
- Create: `app/Models/Concerns/HasSyncUuid.php`, `database/migrations/2026_11_01_000001_add_uuid_to_synced_tables.php`
- Modify models: `Order, OrderItem, OrderReturn, Shift, Expense, ExpenceType, PurchaseInvoice, ReturnPurchaseInvoice, Waste, Stocktaking, Product, Category, Supplier, User`
- Test: `tests/Feature/Sync/SyncUuidTest.php`

**Interfaces:**
- Produces: every listed model has a non-null, unique `uuid` string (ordered UUID). `$model->uuid` is what all later tasks use.

- [ ] **Step 1: Write the failing tests**

```php
it('assigns a uuid on create for every synced model', function (string $model) {
    $record = $model::factory()->create();
    expect($record->uuid)->toBeString()->and(Str::isUuid($record->uuid))->toBeTrue();
})->with([Order::class, OrderItem::class, OrderReturn::class, Shift::class, Expense::class,
          ExpenceType::class, PurchaseInvoice::class, ReturnPurchaseInvoice::class, Waste::class,
          Stocktaking::class, Product::class, Category::class, Supplier::class, User::class]);

it('keeps integer primary keys and route binding by id', function () {
    $order = Order::factory()->create();
    expect($order->getKeyType())->toBe('int')->and($order->getIncrementing())->toBeTrue();
});

it('backfills uuids for rows created before the migration', function () {
    DB::table('categories')->insert(['name' => 'old', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('categories')->update(['uuid' => null]);
    (include database_path('migrations/2026_11_01_000001_add_uuid_to_synced_tables.php'))->backfill();
    expect(DB::table('categories')->whereNull('uuid')->count())->toBe(0);
});
```
(`OrderReturn` has no factory — create via `OrderReturn::create([...])` in a dataset closure.)

- [ ] **Step 2: Run** `./vendor/bin/pest tests/Feature/Sync/SyncUuidTest.php` — Expected: FAIL (column `uuid` missing).

- [ ] **Step 3: Implement**
  - Migration: for each table add `uuid` `char(36)` nullable → `backfill()` (public method, chunked `Str::orderedUuid()` per row) → `unique()` index. Keep nullable at DB level (MySQL alter safety); the trait guarantees values.
  - `trait HasSyncUuid { use HasUuids; public function uniqueIds(): array { return ['uuid']; } }` — `id` stays the incrementing int key because `id` is not in `uniqueIds()`.

- [ ] **Step 4: Run** the test file + `./vendor/bin/pest --parallel` — Expected: all PASS (existing tests untouched).

- [ ] **Step 5: Commit** — `feat(sync): add stable uuids to synced models`

---

### Task 2: Business date on shifts and closed documents

**Files:**
- Create: `database/migrations/2026_11_01_000002_add_business_date_columns.php`
- Modify: `app/Services/ShiftService.php` (`startShift`), `app/Services/WasteService.php` (`closeWaste`), `app/Services/PurchaseService.php` (`closePurchaseInvoice`, `closeReturnPurchaseInvoice`), `app/Services/StocktakingService.php` (`closeStocktaking`)
- Test: `tests/Feature/Sync/BusinessDateTest.php`

**Interfaces:**
- Consumes: `InventoryDailyAggregationService::currentBusinessDate(): Carbon` (existing — open day's date, else today).
- Produces: `shifts.business_date`, `wastes.business_date`, `purchase_invoices.business_date`, `return_purchase_invoices.business_date`, `stocktakings.business_date` (all `date`, nullable). Orders, expenses and returns take `business_date` from their shift.

- [ ] **Step 1: Write the failing tests**

```php
it('stamps the open business day on a shift started after midnight', function () {
    InventoryItemMovementDaily::factory()->create(['date' => '2026-10-29', 'closed_at' => null]);
    Carbon::setTestNow('2026-10-30 00:30:00');
    $shift = app(ShiftService::class)->startShift(500);
    expect($shift->business_date->toDateString())->toBe('2026-10-29');
});

it('stamps business_date when closing waste, purchase, purchase return and stocktaking', function () { … }); // each → '2026-10-29' under the same setup

it('backfills business_date from start_at / closed_at for existing rows', function () { … });
```

- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement** — migration adds columns + backfill (`DATE(start_at)` for shifts, `DATE(closed_at)` for closed docs); each service method sets `business_date => app(InventoryDailyAggregationService::class)->currentBusinessDate()->toDateString()` in the same update; add `'business_date' => 'date'` casts.
- [ ] **Step 4: Run** test file + full suite — Expected: PASS.
- [ ] **Step 5: Commit** — `feat(sync): record business date on shifts and closed documents`

---

### Task 3: Expense categories

**Files:**
- Create: `app/Enums/ExpenseCategory.php`, `database/migrations/2026_11_01_000003_add_category_to_expence_types.php`
- Modify: `app/Models/ExpenceType.php` (cast), `app/Filament/Resources/ExpenseTypes/ExpenseTypeResource.php` (form select + table column), `lang/ar.json`
- Test: `tests/Feature/Sync/ExpenseCategoryTest.php`

**Interfaces:**
- Produces: `enum ExpenseCategory: string { Labor='labor'; Rent='rent'; Utilities='utilities'; Marketing='marketing'; Maintenance='maintenance'; RawMaterials='raw_materials'; Other='other'; public function label(): string }` with Arabic labels: عمالة ورواتب، إيجار، مرافق، تسويق، صيانة، خامات، أخرى. Column `expence_types.category` string, default `other`.

- [ ] **Step 1: Failing tests** — `it('defaults category to other')`; `it('lets an admin set the category from the expense type form')` (Livewire test on the Filament create page, asserts DB value `labor`).
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** migration, enum, cast, Filament `Select::make('category')->options(ExpenseCategory::class)->required()->default('other')`.
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** — `feat(sync): categorize expense types`

---

### Task 4: Platform connection, outbox and batch tables

**Files:**
- Create: migrations `…000004_create_platform_connections_table.php`, `…000005_create_sync_outbox_table.php`, `…000006_create_sync_batches_table.php`; models `PlatformConnection`, `SyncOutbox`, `SyncBatch`; `app/Sync/DocumentType.php`, `app/Sync/Outbox.php`
- Test: `tests/Feature/Sync/OutboxTest.php`

**Interfaces:**
- Produces:
  - `platform_connections`: `id, url, branch_uuid, token (encrypted cast), branch_secret (encrypted cast), timezone, status ('active'|'revoked'), paired_at, last_success_at, last_heartbeat_at, last_error, timestamps`. `PlatformConnection::active(): ?PlatformConnection` (cached per request; `PlatformConnection::flushCache()` for tests).
  - `sync_outbox`: `id, doc_type string(32), doc_uuid string(36), doc_key string(32) nullable (date for date-keyed docs, Task 7), op string(8), priority tinyint default 5, occurred_at, batch_id nullable, sent_at nullable, attempts smallint default 0, next_attempt_at nullable, failed_at nullable, last_error text nullable`; indexes `(sent_at, failed_at, priority, id)` and `(doc_type, doc_uuid)`.
  - `sync_batches`: `id, uuid unique, seq_from, seq_to, docs_count, status ('pending'|'sent'|'partial'|'failed'), response json nullable, timestamps`.
  - `enum DocumentType: string { Order='order'; OrderReturn='order_return'; Shift='shift'; Expense='expense'; PurchaseInvoice='purchase_invoice'; PurchaseReturn='purchase_return'; Waste='waste'; Stocktaking='stocktaking'; InventoryDay='inventory_day'; DaySummary='day_summary'; Product='product'; Recipe='recipe'; Category='category'; Supplier='supplier'; ExpenseType='expense_type'; Staff='staff' }`
  - `Outbox::record(DocumentType $type, string $uuid, string $op = 'upsert', int $priority = 5, ?string $key = null): void` — no-op when `PlatformConnection::active()` is null.
  - `config('app.version')` — add `'version' => env('APP_VERSION', '1.0.0')` to `config/app.php` and `APP_VERSION` to `.env.example`; `deploy.sh` sets it from `git describe --tags --always`. Sent as `pos_version` everywhere.

- [ ] **Step 1: Failing tests**
```php
it('records nothing when the POS is not paired', function () {
    Outbox::record(DocumentType::Order, (string) Str::uuid());
    expect(SyncOutbox::count())->toBe(0);
});
it('records a row when paired', function () { pairPlatform(); Outbox::record(DocumentType::Order, $u = (string) Str::uuid());
    expect(SyncOutbox::first())->doc_type->toBe('order')->doc_uuid->toBe($u)->op->toBe('upsert')->priority->toBe(5); });
it('rolls the outbox row back with the business transaction', function () {
    pairPlatform();
    try { DB::transaction(function () { Outbox::record(DocumentType::Order, (string) Str::uuid()); throw new RuntimeException; }); } catch (RuntimeException) {}
    expect(SyncOutbox::count())->toBe(0);
});
it('stores the token encrypted at rest', function () { pairPlatform(token: 'abc');
    expect(DB::table('platform_connections')->value('token'))->not->toBe('abc'); });
```
Add helper `pairPlatform(string $token = 'test-token'): PlatformConnection` in `tests/Pest.php`.
- [ ] **Step 2: Run** — FAIL. **Step 3: Implement.** **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** — `feat(sync): add platform connection, outbox and batch tables`

---

### Task 5: Observers that feed the outbox

**Files:**
- Create: `app/Sync/SyncOutboxObserver.php`
- Modify: `app/Providers/AppServiceProvider.php` (register observer on the models below); services found by the bulk-write grep in Step 4
- Test: `tests/Feature/Sync/OutboxObserverTest.php`

**Interfaces:**
- Consumes: `Outbox::record`, `DocumentType`, model `uuid`s.
- Produces — rules (event → recorded document):

| Model | Event | Condition | Records |
|---|---|---|---|
| `Order` | saved | status ∈ {completed, cancelled} | `order` (order uuid) |
| `Order` | deleted | status final | `order` op `delete` |
| `OrderItem`, `Payment` | saved, deleted | parent order final | `order` (parent uuid) |
| `OrderReturn` | created | — | `order_return` + `order` |
| `Shift` | saved | — | `shift` |
| `Expense` | saved / deleted | — | `expense` / `expense` op `delete` |
| `PurchaseInvoice`, `ReturnPurchaseInvoice`, `Waste`, `Stocktaking` | saved / deleted | `closed_at` not null | `purchase_invoice` / `purchase_return` / `waste` / `stocktaking` (op `delete` on delete) |
| `Product`, `Category`, `Supplier`, `ExpenceType`, `User` | saved / deleted | — | `product` / `category` / `supplier` / `expense_type` / `staff` |
| `ProductComponent` | saved / deleted | — | `recipe` (parent product uuid) |

- [ ] **Step 1: Failing tests** (paired; **no bare `Event::fake()`**) — one test per row of the table, e.g.
```php
it('records the order when it is completed, not while it is processing', function () {
    pairPlatform();
    $order = Order::factory()->create(['status' => OrderStatus::PROCESSING]);
    expect(SyncOutbox::where('doc_type', 'order')->count())->toBe(0);
    $order->update(['status' => OrderStatus::COMPLETED]);
    expect(SyncOutbox::where(['doc_type' => 'order', 'doc_uuid' => $order->uuid])->count())->toBe(1);
});
it('records the parent order when an item of a completed order changes', …);
it('does not record a waste document until it is closed', …);
it('records a delete tombstone when an expense is deleted', …);
it('records the parent product recipe when a component changes', …);
it('records nothing for any model when not paired', …);
```
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** one observer class with `saved`, `deleted` methods dispatching on `$model::class` via a `match`. Register with `Model::observe(SyncOutboxObserver::class)` for each model in `AppServiceProvider::boot()`.
- [ ] **Step 4: Grep for bulk writes** that skip observers: `rg "::where\(.*\)->update\(|->update\(\[" app/Services app/Http app/Filament` — for every hit on a synced table that can touch a *final/closed* record, add an explicit `Outbox::record(...)` after it in the same transaction. Add a test for each one found (at minimum: closed-day order cancellation/return paths in `ClosedDayOrderCancellationAndReturnTest` scenarios still produce an `order` row).
- [ ] **Step 5: Run** `./vendor/bin/pest --parallel` — PASS (existing suite unaffected because not paired).
- [ ] **Step 6: Commit** — `feat(sync): feed outbox from business model events`

---

### Task 6: Document builders (payload snapshots)

**Files:**
- Create: `app/Sync/Payload.php`, `app/Sync/Builders/DocumentBuilder.php`, all builders listed in File Structure except `InventoryDay`/`DaySummary` (Task 7), `app/Sync/BuilderRegistry.php`
- Test: `tests/Feature/Sync/Builders/*Test.php` (one file per builder)

**Interfaces:**
- `interface DocumentBuilder { public function type(): DocumentType; /** @return array<string,mixed>|null null = record no longer exists → send as delete */ public function build(string $uuid): ?array; }`
- `BuilderRegistry::for(DocumentType $type): DocumentBuilder`
- `Payload::money(float|string|null $v): ?string` (2 dp, `'.'`, no thousands sep) · `Payload::qty(float|string|null $v): ?string` (3 dp) · `Payload::ts(?CarbonInterface $t): ?string` (ISO-8601, `Africa/Cairo` offset) · `Payload::customerHash(?string $phone): ?string`.
- `data` keys per type: **exactly** the field lists in spec §4.3. Notes for fields not 1:1 with columns:
  - order `business_date` = shift's `business_date`; `profit` = `orders.profit`; items `unit_cost` = `order_items.cost`; items `product_ref` from product; payments carry `method, amount, shift_uuid, paid_at (= created_at)`.
  - order_return `business_date` = return's shift `business_date`; items reference `order_item_uuid`.
  - expense `business_date` = shift's; `expense_type_uuid`.
  - staff `role` = first Spatie role name.

- [ ] **Step 1: Failing tests** — for each builder one "golden" test building a fixture and asserting the exact array, e.g.
```php
it('builds a completed order snapshot with items and payments', function () {
    // shift business_date 2026-10-29, order total 400, discount 20, tax 0, 2 × 150 item cost 62.25, cash payment 400
    $data = app(OrderBuilder::class)->build($order->uuid);
    expect($data)->toMatchArray([
        'number' => '57', 'business_date' => '2026-10-29', 'status' => 'completed', 'type' => 'delivery',
        'sub_total' => '420.00', 'discount' => '20.00', 'total' => '400.00',
    ])->and($data['items'][0])->toMatchArray(['quantity' => '2.000', 'price' => '150.00', 'unit_cost' => '62.25'])
      ->and($data['payments'][0])->toMatchArray(['method' => 'cash', 'amount' => '400.00'])
      ->and($data)->not->toHaveKeys(['customer_name', 'customer_phone', 'address']);
});
it('returns null for a uuid that no longer exists', …);
it('formats money as 2-decimal strings', fn () => expect(Payload::money(1234.5))->toBe('1234.50'));
it('hashes customer phone with the branch secret', …);
```
- [ ] **Step 2: Run** — FAIL. **Step 3: Implement** builders with eager loading (one query set per document). **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** — `feat(sync): document payload builders`

---

### Task 7: Day close documents (`inventory_day`, `day_summary`)

**Files:**
- Create: `app/Sync/Builders/InventoryDayBuilder.php`, `app/Sync/Builders/DaySummaryBuilder.php`
- Modify: `app/Services/InventoryDailyAggregationService.php` (`closeDay()` — record both docs inside its transaction)
- Test: `tests/Feature/Sync/DayCloseDocumentsTest.php`

**Interfaces:**
- Document uuid for date-keyed docs: `Uuid::uuid5($connection->branch_uuid, "{$type}:{$date}")->toString()`, recorded with `Outbox::record($type, $uuid, key: $date)` (the `doc_key` column from Task 4). Builder signature stays `build(string $uuid)`; `BatchSender` (Task 8) passes `doc_key` first via `DocumentBuilder::withKey(?string $key): static` (default no-op in other builders; add it to the interface here).
- `day_summary` fields (all for `business_date = date`, from POS tables, **must match platform K-definitions**): `orders_count` (non-deleted orders), `completed_count`, `net_total` = Σ(`total` − `tax`) completed, `discount_total` = Σ `discount` completed, `cogs_total` = Σ item `quantity × cost` of completed orders, `expenses_total`, `waste_total` (closed wastes with that business_date).
- `inventory_day.rows[]` from `inventory_item_movement_daily` for that date: `product_uuid, start_qty, incoming_qty, sales_qty, return_sales_qty, waste_qty (= return_waste_quantity), end_qty`.

- [ ] **Step 1: Failing tests** — `it('records inventory_day and day_summary when the day is closed')`; `it('computes day_summary totals from completed orders of shifts with that business date')` (fixture: 3 completed + 1 cancelled order across midnight; asserts exact strings); `it('produces the same uuid for the same branch and date')`.
- [ ] **Step 2–4:** run FAIL → implement → run PASS.
- [ ] **Step 5: Commit** — `feat(sync): send inventory day and day summary on day close`

> Note: `DailySnapshot` is never written in larament today (only referenced) — do **not** use it as the checksum source.

---

### Task 8: Batch sender and `platform:sync`

**Files:**
- Create: `app/Sync/PlatformClient.php`, `app/Sync/BatchSender.php`, `app/Console/Commands/PlatformSyncCommand.php`
- Test: `tests/Feature/Sync/BatchSenderTest.php`

**Interfaces:**
- `PlatformClient::sendBatch(PlatformConnection $c, array $envelope, string $batchUuid): Illuminate\Http\Client\Response` — gzip body (`gzencode(json, 6)`), headers per spec §4.1, timeout 30 s, connect timeout 10 s, TLS verification **on**.
- `BatchSender::run(int $maxSeconds = 50): SyncRunResult` (`readonly class SyncRunResult { int $batches; int $sent; int $rejected; int $failed; ?string $error; }`)
- `php artisan platform:sync` → calls `BatchSender::run()`, prints the result; exit 0 even on network failure (failures are recorded, not thrown).

Algorithm (not determined by the signature):
```
loop until no due rows or elapsed > maxSeconds:
  batch = SyncBatch where status = 'pending' oldest first            // retry same uuid
       ?? new batch from ≤500 due rows (sent_at null, failed_at null,
          next_attempt_at null or ≤ now) ORDER BY priority DESC, id ASC
          → assign batch_id in one transaction
  group batch rows by (doc_type, doc_uuid): version = max(id), op = op of max(id) row
  data = op=='delete' ? null : builder->build(uuid); if data === null → op = 'delete'
  envelope = {schema_version:1, batch_uuid, branch_uuid, pos_version: config('app.version'),
              sent_at, seq_from: min(id), seq_to: max(id), documents:[…]}
  response:
    202/200 → rows of accepted docs: sent_at = now; rejected docs: attempts++, last_error = reason,
              batch_id = null, next_attempt_at = backoff(attempts), failed_at = now if attempts ≥ 10;
              batch.status = rejected ? 'partial' : 'sent'; connection.last_success_at = now
    409     → same as 202 using response body's stored result
    401     → connection.status = 'revoked', last_error = 'token rejected'; stop
    413     → split: release the second half of the rows (batch_id = null), resend smaller batch
    else / exception → all rows attempts++, next_attempt_at = backoff; batch stays 'pending'; stop
```

- [ ] **Step 1: Failing tests** (all with `Http::fake`):
```php
it('sends due rows as one gzip batch and marks them sent', …);           // asserts header Idempotency-Key, Content-Encoding gzip, 2 docs
it('coalesces many rows of the same document into one with the highest version', …);
it('reuses the same batch uuid after a 500', …);                          // 1st call 500, 2nd call 202 → same Idempotency-Key both times
it('treats 409 as success', …);
it('marks only rejected documents for retry with backoff', …);            // next_attempt_at = now + 1 min after first rejection
it('moves a row to failed after 10 attempts', …);
it('stops and flags the connection revoked on 401', …);
it('sends a delete when the record was hard-deleted before sending', …);
it('sends live rows (priority 5) before backfill rows (priority 1)', …);
it('does nothing when not paired', fn () => …Http::assertNothingSent());
```
- [ ] **Step 2: Run** — FAIL. **Step 3: Implement.** **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** — `feat(sync): batch sender and platform:sync command`

---

### Task 9: Pairing, heartbeat and backfill

**Files:**
- Create: `app/Sync/PairingService.php`, `app/Sync/HeartbeatService.php`, `app/Sync/BackfillService.php`, commands `platform:heartbeat`, `platform:backfill`, `platform:prune`
- Modify: `routes/console.php` (schedule), `PlatformClient` (add `pair`, `heartbeat`)
- Test: `tests/Feature/Sync/PairingTest.php`, `HeartbeatTest.php`, `BackfillTest.php`

**Interfaces:**
- `PairingService::pair(string $url, string $code): PlatformConnection` — `POST {url}/api/v1/pair {code, pos_version}` → expects `{branch_uuid, token, branch_secret, timezone}`; saves an `active` connection (replacing any previous), then `BackfillService::queueCatalog()` and `BackfillService::queueRange(today − 90 days, today)`. Throws `PairingFailed` (Arabic message) on non-2xx.
- `PairingService::unpair(): void` — status `revoked`, keeps outbox rows.
- `BackfillService::queueCatalog(): int` and `queueRange(CarbonInterface $from, CarbonInterface $to): int` — **insert-select** outbox rows with `priority = 1` for: final orders, returns, shifts, expenses, closed purchases/returns/wastes/stocktakings, closed inventory days + day summaries in range; catalog = all products, recipes, categories, suppliers, expense types, staff. Returns rows inserted. Skips docs already pending (`doc_type, doc_uuid` with `sent_at` null).
- `HeartbeatService::beat(): array` — `POST /api/v1/heartbeat` with `{pos_version, pending_count, oldest_pending_at, failed_count, last_success_at, server_time, business_date_open}`; executes commands: `resync {from,to}` → `queueRange`; `rotate_token` → `POST /api/v1/pair/rotate` and store the new token.
- `platform:backfill {--from=} {--to=} {--catalog}` wraps the service. `platform:prune` deletes sent outbox rows older than 30 days and batches older than 30 days.
- Schedule in `routes/console.php`:
  `Schedule::command('platform:sync')->everyMinute()->withoutOverlapping(5)->runInBackground();`
  `Schedule::command('platform:heartbeat')->everyFiveMinutes()->withoutOverlapping();`
  `Schedule::command('platform:prune')->dailyAt('04:00');`

- [ ] **Step 1: Failing tests** — `it('pairs and queues catalog plus 90 days of history')`; `it('throws an Arabic error when the code is invalid')`; `it('queues a resync range received in the heartbeat response')`; `it('rotates the token on command')`; `it('does not duplicate pending rows on repeated backfill')`; `it('backfills only final orders and closed documents')`; `it('prunes sent rows older than 30 days only')`.
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5: Commit** — `feat(sync): pairing, heartbeat commands and backfill`

---

### Task 10: Filament page "ربط المنصة"

**Files:**
- Create: `app/Filament/Pages/PlatformConnectionPage.php` (+ view if needed), strings in `lang/ar.json`
- Modify: navigation group used by `Settings` page; restrict to admin role (same gate as `app/Filament/Pages/Settings.php`)
- Test: `tests/Feature/Filament/PlatformConnectionPageTest.php`

**Interfaces:**
- Consumes: `PairingService`, `SyncOutbox`, `SyncBatch`, `PlatformConnection`.
- Page shows: status (غير مربوط / متصل / متأخر / مرفوض), platform URL, branch uuid, last success, last heartbeat, pending count, oldest pending age, failed count, last error.
- Actions: **ربط** (form: url + code → `pair`), **فك الربط** (confirm), **مزامنة الآن** (runs `BatchSender::run(20)`), **إعادة محاولة الفاشل** (failed rows: `failed_at = null, attempts = 0, next_attempt_at = null`), **إعادة إرسال فترة** (date range → `queueRange`).
- Table of failed rows: type, uuid, attempts, last_error.

- [ ] **Step 1: Failing tests** — page renders for admin, forbidden for cashier; pair action with `Http::fake` success stores connection; retry-failed resets rows.
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5: Commit** — `feat(sync): platform connection admin page`

---

### Task 11: Harden existing public API routes and enable the scheduler on servers

**Files:**
- Create: `app/Http/Middleware/VerifyApiKey.php`
- Modify: `routes/api.php`, `app/Http/Controllers/Api/ManagementController.php` (header only, drop `?key=`), `config/app.php` (`web_api_key` from `WEB_API_KEY`), `.env.example`, `deploy.sh`, `refresh.sh`
- Test: `tests/Feature/Api/ApiKeyTest.php`

**Interfaces:**
- `VerifyApiKey` checks header `X-Api-Key` against `config('app.web_api_key')` with `hash_equals`; 401 JSON otherwise. Applied to: `/web-orders/*`, `/can-accept-order`, `/get-shift-id`, `/save-order/{order}`, `/all-products*`, `/get-products-*`, `/products/product_search`, `/validate_products`. `/check` stays public.
- **Coordinated change:** the restaurant website and slave POS nodes (`BranchService` master calls) must send `X-Api-Key` — add it to `BranchService::$querySettings` headers from the same config. Deploy websites first with the header (ignored by old POS), then POS.
- `deploy.sh`: ensure crontab entry `* * * * * cd /var/www/turbo_restaurant/larament && php artisan schedule:run >> /dev/null 2>&1` exists (idempotent `grep -q || append`) and that a queue worker is not required by the connector (commands run synchronously).

- [ ] **Step 1: Failing tests** — request without key → 401; with key → 200; `/api/check` without key → 200; management `?key=` no longer accepted, header accepted; `BranchService` requests include `X-Api-Key` (`Http::assertSent`).
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5: Run** `composer review` — Expected: Pint clean, all Pest tests pass, PHPStan level 5 no errors.
- [ ] **Step 6: Commit** — `fix(api): require api key on public endpoints; enable scheduler on deploy`

---

## Done when

- A fresh POS pairs with a platform staging URL, backfills 90 days, and the platform's day totals match every `day_summary`.
- Pulling the network cable for 2 hours during service, then reconnecting, sends everything with no duplicates and no gaps.
- `composer review` passes.
