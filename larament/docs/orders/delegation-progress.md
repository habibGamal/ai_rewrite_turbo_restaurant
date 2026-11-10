# Delegation progress — ordering refactor and dynamic order types

Plan: [dynamic-order-types-and-floor.md](dynamic-order-types-and-floor.md)
Integration branch: `feature/order-types` (off `feature/kds`). Not pushed.
Each implementer run worked in its own worktree under `D:/orders-wt/<task>` on branch `orders/<task>`; the
orchestrator reviewed, re-ran the gates, committed, and merged.

## Baseline (HEAD `fc9aa3f`, before any change)

- Pest (serial, 2 GB): 109 failed, 14 skipped, 1953 passed. Many order tests were already failing
  (OrderManagementIntegrationTest, OrderStockConversion*, parts of OrderControllerFeatureTest, report page tests).
- PHPStan level 5: 266 errors.
- `pest --parallel` crashes on this machine (workers hit the 128 MB memory limit), so gates ran serially (~10 min).
- `python D:/orders-wt/gate_diff.py <task>` diffs failing test names and PHPStan errors against the baseline.

## Tasks

| Step | Task | Implementer | Commit / merge |
|---|---|---|---|
| 0 | Order core: `locked()`, `OrderItemPricingService`, discount policy, change tracking in the transaction, customer recalc, `OrderOpeningService`, `CreateOrderDTO` + `OrderOpening` | OpenCode grok-4.7 | `af8b1ac` / `0e018ca` |
| 0 | Payments: `Tenders`, `OrderPaymentService::pay()` | OpenCode grok-4.7 | `e70366d` / `ff085b5` |
| 0 | `ShiftController`, order-number lock, unused repository methods | OpenCode deepseek → grok | `c877c2d` / `421b646` |
| 0 | Drop the repository interfaces and `OrderServiceProvider` | inline | `27577ed` |
| A | `order_types` catalog + six behavior strategies | OpenCode grok-4.7 | `8b96664` / `112072a` |
| B1 | Enum cast removed, `OrderDisplay`, catalog validation, KDS/sync/print blades | OpenCode grok-4.7 | `feaaf55` / `041eefd` |
| B2 | Order-type admin resource, `CASHIER_TABS`, `CashierTabLayout`, Settings section | OpenCode deepseek | `7053c96` / `c148419` |
| B3 | Data-driven cashier desk: `OrderTypeTab`, change-type modal, ManageOrder, receipts | OpenCode glm → grok | `d7b3f6c` / `7f7fdee` |
| — | Settings page as tabs | Codex | `ea1a5ce` / `74d00f2` |
| C | Companies partial payment, تحصيل الآجل, حسابات الشركات, payments by shift | OpenCode grok-4.7 | `a205d71` / `8e9deb0` |
| D | Report columns and shift cards from the catalog | Codex (2 turns) | `27c9d99` / `99cb7b1` |
| F | Dine-in floor: layout files, designer, floor tab, per-type tables | Codex (2 turns) | `82df16e` / `f2f6097` |
| E | طلب خارجي: sellers, GuestBill, receipts, shift money, settlements, sync | Codex (2 turns) | `4adc090` / `dc42869` |
| — | Delete the OrderType enum, test references to string codes, drop the setter shim | inline | final commit |

Two merges on `feature/order-types` were not made by this run: `dd39a18` and `9feca71` (feature/kds) and `7a0d5a1`
(docs for badge login / POS tab permissions). They did not conflict.

## Review fixes made by the orchestrator

- w1a: the pricer kept product names in instance state between calls; change-log names now come from the saved items.
- w1b: my brief had empty tenders skip the payment-status refresh (a zero-total order would stay `pending`); restored.
- B1: a hardcoded `code !== 'talabat'` in a strategy; replaced by printing the takeaway customer block only when a
  customer is linked.
- B3: a tsconfig exclude for a dead component; deleted the component instead.
- C: my brief applied the "payment in a closed shift blocks cancel" rule to every order; narrowed to partial-payment
  behaviors and added a test that a normal order from a closed shift still cancels.
- D: per-type SQL aliases could collide with the total columns (a type coded `total`); prefixed with `type_`. Removed
  duplicated parsing of the tab setting, constructor injection, and the Services-suffix rename.
- F: designer and service were written as packed one-liners with `prompt()`; rewritten into components with an antd
  form and the app layout; the 404 moved from the service to the controller.
- E: the first Codex turn stopped half-way and said so; a second turn finished it. Updated the sync snapshot test for
  the new `external_orders` key; replaced inline class names and `app()` lookups in the order services.

## Final gate

On the final `feature/order-types` tree (all merges + enum removal), with `BROADCAST_CONNECTION=log`:

- `npm run build`: green. Pint: clean.
- Pest (serial): 104 failed, 14 skipped, 2046 passed — baseline was 109 failed / 1953 passed. No new failing test:
  the two names outside the baseline list are the flaky InventoryItem search and the CompleteOrderAction
  multi-payment test that already failed (now with OrderException instead of InvalidArgumentException).
- PHPStan: no new error; 17 fewer unique errors than the baseline.
- `grep إسناد` over app, resources, tests, database: no match.

## Needs your eyes

- Visible changes:
  - Kitchen tickets print the catalog labels (صالة / تيك أواي / دليفري) instead of في المطعم / خارجي / توصيل.
    Rename them in أنواع الطلبات if the kitchen wants other words.
  - A takeaway receipt with no linked customer no longer prints "اسم العميل : -" / "رقم الهاتف : -".
  - Cashier tab labels render as colored tags; delivery cards also show the address; web order pages use the catalog
    names (اونلاين دليفري / اونلاين تيك أواي).
  - Only companies orders may complete partly paid; every other type must be paid in full.
  - The شركات and تحصيل الآجل tabs are now visible by default.
- Not verified in a browser or on a printer: the floor designer, the floor tab, the receivables tab, the external
  order controls and the new receipt blocks were covered by feature tests and the build only.
- The test suite needs a running Reverb server unless `BROADCAST_CONNECTION` is overridden; gates ran with
  `BROADCAST_CONNECTION=log`. One-line fix: `<env name="BROADCAST_CONNECTION" value="null"/>` in phpunit.xml.
- Flaky tests (random faker data, pass on rerun): Supplier create/update, Customer unique phone/update, Driver create,
  ExpenseType / InventoryItem / ManufacturedProduct / RawMaterialProduct search.
- `tests/Unit/Actions/Orders/*` wrap `OrderService` and are used only by their own tests; candidates for deletion.
- Plan deviations: the order-number skip loop stays (website numbers); report columns are generated SQL from the
  catalog (sortable Filament tables) rather than PHP shaping; the enum was deleted at the very end, not after Phase B.
- `storage/app/floor-layouts` holds the floor files; add it to the server backup.
- Cost: about $70 on grok-4.7 across the OpenCode `complex` runs; the free lanes and Codex added no per-token cost.
- Your uncommitted `README.md` and `docs/` changes in the main checkout were left out of every commit.

## Follow-up: floor designer UX and cashier polish (Antigravity, gemini-4-argon-high)

| Task | Commit / merge |
|---|---|
| Floor designer redesign (tools, drag/resize, auto numbers, checks, panel, undo, zoom), split into components, cashier floor states + legend bar | `7b587b4` / `c24690f` |
| Order-type icon picker (26 curated heroicons), icon top bar without colors, إضافة طلب خارجي in the action grid | `491facd` / `74b5488` |

- Review fixes: a single 1,850-line designer component split into nine files; the layout state no longer shadows
  the browser's `document`; a hardcoded `order.type !== "companies"` replaced by a `canAddExternalOrder` flag
  from the type's behavior (with a test).
- Gates: both branches 103 failed (baseline 109), no new PHPStan error, build green.
- Checked in the browser at 1366×768: designer toolbar/canvas/panel, add-table tool (auto numbers 3 and 4, also on
  top of a section), undo; cashier icon tabs, orange occupied table with timer, legend bar; the external-order button
  in the action grid.
- Open: a section's name is hidden when a table sits on the section's top-right corner (seen on "area 2", in the
  designer and the cashier floor).

## Follow-up: outsource products, seller accounts, credit settings

| Task | Implementer | Commit |
|---|---|---|
| Outsource products per seller (الأصناف), picked in the طلب خارجي modal, priced on the server | Codex | `4925a7c`, label fix `fec9a96` |
| طلب خارجي list redesign on the order screen | Antigravity gemini-3.8-flash-high | `5d91781` |
| Per-seller account page (stats, orders with date/shift/status filters, payouts) | Antigravity gemini-3.8-flash-high | `e63d435` |
| Credit settings per order type (partial payment, pay later, customer required) | Antigravity gemini-3.8-flash-high | `196d6a5`, layout fix `c2e4318` |

- The argon runs failed (network lookup error, then "Individual quota reached", resets in ~6 days); the same briefs
  ran on gemini-3.8-flash-high.
- Review fixes: Arabic labels on the products relation manager; removed a leftover repository alias; the credit
  section now spans the full form width.
- Gates: each branch 103 failed (baseline 109), no new PHPStan error, build green; merged tree 73 affected tests pass.
- Dev database migrated (external_products, credit settings). Checked in the browser: products relation manager,
  modal empty state, credit section, seller account page.
- Not checked in the browser: ordering an actual product, closing an order with pay later, collecting it from
  تحصيل الآجل (covered by feature tests).
