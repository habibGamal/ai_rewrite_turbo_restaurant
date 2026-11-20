# Badge Quick Login, POS Tab Permissions, and Order Line Audit — Design

Date: 2026-11-09
Workstreams: Security, Authentication & POS / Back-office reporting
App: `larament/` (Laravel 11, Filament v4, React + Inertia)

This document covers two independent features. They can be built and shipped separately (Feature A first is fine; nothing in B depends on A).

---

## Feature A — Badge quick login + per-tab POS permissions

### Intent

During rush hours, staff sharing one POS terminal lose time typing email + password to switch users. Staff also currently see every tab on the orders screen, including sensitive ones (expenses, completed orders, cancelled orders).

Goal: a cashier walks up, scans their printed badge, and is on the orders screen instantly; when done they press logout and the terminal is back at the scan screen for the next person. Each orders-screen tab is visible only to users with that tab's permission.

### Current state (verified in code)

- Logout already exists: floating button in `resources/js/Layouts/CashierLayout.tsx`. After `POST /logout` it hard-navigates to `/admin/login` (Filament password login).
- `GET /` and `/dashboard` redirect to `/admin`.
- The shift is **global** (`ShiftMiddleware` looks for any open shift), so switching users does not touch the shift.
- `orders.user_id` is set on creation, so orders are attributed to whoever was logged in.
- Orders screen tabs are now dynamic: `CashierTabLayout` (`app/Orders/CashierTabLayout.php`) merges the `CASHIER_TABS` setting with the `order_types` catalog into `type:{code}` and `system:{name}` tabs. None are permission-gated (layout visibility applies to everyone).
- `OrderController::index` sends all shift orders, all shift expenses and expense types to every POS user.
- Expense routes (`/expenses/*`) are gated only by `pos.access`.
- Permissions are Spatie, catalogued in `database/seeders/RolesAndPermissionsSeeder::permissionsCatalog()`; new permissions for existing installs have so far been added by migrations (e.g. `2026_11_08_000008_add_kitchen_station_permissions.php`). `deploy.sh` does not run any seeder today. The Roles Filament screen builds its checkboxes from that static catalog.
- Frontend has `CanAccess` component and `auth.user.permissions` shared via Inertia.
- No barcode-rendering library is installed.
- `ManageOrder.tsx` already listens to keyboard input for product barcodes — the badge scan screen is a separate page, so no conflict.

### Decisions made by the user

1. Badge scan logs a user into the **POS only**. It never grants access to the Filament admin panel. Admin users who scan their badge also get POS-only; to reach the admin panel they log in with a password.
2. **Every** orders-screen tab gets its own permission, including cancelled — and tabs are dynamic, so permissions must follow the order-type catalog.
3. Protecting a badge is the cashier's responsibility. No PIN/second factor on top of the badge.
4. Logout flow stays a single button press, as today.
5. Permissions are created/maintained by a seeder run from `deploy.sh`, not by migration files.

### Behaviour

**Scan screen (new Inertia page, e.g. `Auth/BadgeLogin`)**
- Full-screen, Arabic/RTL, large "امسح الكارت" prompt, an always-focused (auto-refocusing) input that captures scanner keystrokes and submits on Enter.
- On success: session starts, redirect to `orders.index` (kitchen-role users → `kitchen.index`, matching existing `AuthenticatedSessionController::store` behaviour).
- On failure: clear the input, show a short error ("الكارت غير صالح"), stay focused.
- Small link "الدخول بكلمة المرور" → existing Inertia password login (`/login`, `Auth/Login`).
- This becomes the landing page after POS logout (CashierLayout logout target changes from `/admin/login` to the scan screen). The KDS logout (`KdsSettingsDrawer.tsx`) gets the same target.

**Badge login endpoint**
- `POST` route, guest-only, rate-limited (throttle per IP).
- Looks up an active (not soft-deleted) user by badge code. User must have `pos.access` (or `kitchen.access` for kitchen users); otherwise reject with the same generic error.
- Logs in, regenerates session, and marks the session as badge-authenticated (e.g. `session('auth_via_badge') = true`).

**POS-only enforcement**
- `User::canAccessPanel()` returns false when the current session is badge-authenticated. Filament then refuses the panel; the user must log out and log in with password.
- The "الادارة" floating button in CashierLayout is hidden for badge sessions (share an `auth.viaBadge` flag via Inertia).

**Badge management (Filament Users resource, `app/Filament/Resources/Users/`)**
- New nullable unique column `users.badge_code`.
- Action "توليد كارت" / "إعادة توليد الكارت": generates a new random code (replaces old → old badge stops working immediately).
- Action "طباعة الكارت": printable page with user name + barcode (Code 128) of the code, sized for a badge/label printer.
- Action "إلغاء الكارت": clears the code.
- Gated by `manage_users`.

**Per-tab permissions — dynamic tabs**

Tabs are dynamic on this branch (commits `8b96664`, `7053c96`; spec `docs/orders/dynamic-order-types-and-floor.md` §1–3). The desk renders the list returned by `App\Orders\CashierTabLayout::resolve($shift)`, whose keys are:

- `type:{code}` — one per active `order_types` row (`OrderTypeRow`). Admins create new rows at runtime from `OrderTypeRowResource`; `code` is a slug fixed after create.
- `system:{name}` — fixed set `receivables`, `expenses`, `cancelled`, `completed` (`CashierTabLayout::SYSTEM_TABS`).

Layout settings (`CASHIER_TABS`) decide order and global visibility. Permissions decide **who** sees a tab. Both apply: a user sees a tab only if the layout shows it **and** they hold its permission.

Permission names mirror the layout key, one per tab:

| Tab key | Permission | Label (ar) in roles screen |
|---|---|---|
| `type:{code}` | `pos.tab.type.{code}` | تبويب: `{order_types.label}` |
| `system:receivables` | `pos.tab.system.receivables` | تبويب تحصيل الآجل |
| `system:expenses` | `pos.tab.system.expenses` | تبويب المصاريف |
| `system:cancelled` | `pos.tab.system.cancelled` | تبويب الطلبات الملغية |
| `system:completed` | `pos.tab.system.completed` | تبويب الطلبات المنتهية |

A single helper (e.g. `CashierTabLayout::permissionFor(string $key)`, or a small `TabPermissions` class) maps key ↔ permission so no code builds the string by hand.

**Where tab permissions come from**
- **Deploy seeder** (source of truth, see "Permissions seeding" below) creates `pos.tab.system.*` and one `pos.tab.type.{code}` per existing `order_types` row (active or not), and deletes `pos.tab.type.*` permissions whose row no longer exists.
- **Runtime type creation:** the deploy seeder only runs on deploy, but an admin can add a type any time. An `OrderTypeRow` `created` hook (observer or model event) creates `pos.tab.type.{code}` immediately and grants it to `admin` and every role holding `pos.access`, so the new tab is usable before the next deploy. Deleting a custom type deletes its permission. Both paths call the same sync method the seeder uses, so there is one implementation.
- **Roles screen:** `RoleResource` / `EditRole` build their checkbox groups from `RolesAndPermissionsSeeder::permissionsCatalog()` (static today). Add a group "تبويبات الكاشير" built at call time from `order_types` rows + the system tabs, so new types show up in the roles screen without code changes. Guard with `Schema::hasTable('order_types')` for fresh installs. (Builder may move the catalog out of the seeder into an `app/` class; both callers must keep working.)

**Enforcement**
- `CashierTabLayout` (or the controller right after it) filters the resolved tabs by the current user's tab permissions before they become the `tabs` Inertia prop. A layout `forcedVisible` tab (hidden tab with open orders) is still dropped for a user without its permission.
- Frontend: tabs not in the prop are not rendered (hidden, not disabled). URL-hash fallback to the first visible tab already exists in the layout spec and now also covers "not permitted". If the user has zero tabs, show an empty-state message.
- Backend data (source of truth): `OrderController::index` sends orders only for permitted type codes, cancelled/completed orders only with their system permission, receivables only with `pos.tab.system.receivables`, expenses/expense types only with `pos.tab.system.expenses`.
- Expense routes (`/expenses/*`) require `pos.tab.system.expenses` in addition to `pos.access` (existing `expense.create` / `expense.delete` checks stay).
- Opening a new order of a type, and `updateOrderType()` / `ChangeOrderTypeModal`, require the target type's permission (on top of the layout-visibility check the order-types spec already adds).
- Recommended (builder may confirm in tests): `orders.manage/{order}` and other per-order actions refuse an order whose type permission the user lacks, so a hidden tab's order cannot be opened by ID.
- Admin role holds every tab permission.

### Permissions seeding (deploy-time, not migrations)

User decision: permissions are controlled by a seeder that runs from `deploy.sh`, not by migration files.

- `deploy.sh` runs, right after `php artisan migrate --force`: `php artisan db:seed --class=RolesAndPermissionsSeeder --force`, then clears the permission cache. (`refresh.sh` does not need it.)
- **The seeder must become safe to re-run on every deploy.** Today it calls `syncPermissions()` on cashier, kitchen, viewer and watcher, which would wipe any change the owner made in the roles screen on every deploy. New behaviour:
  - Create missing permissions (static catalog + dynamic tab permissions); never rename.
  - Delete only orphaned `pos.tab.type.*` permissions (type row gone).
  - Create roles if missing.
  - `admin` always gets every permission (sync is fine for admin).
  - Other roles: apply their **default** permission list only to permissions **created in this run** (and to all defaults when the role itself was just created). Never revoke. This way a fresh install gets the defaults, a deploy that introduces a new permission grants it to the roles that should have it by default, and owner customizations survive.
  - The dynamic cashier-settings toggles (`allow_cashier_discounts` etc.) keep working for fresh installs; on re-run they only add, never remove. (Builder: confirm with the existing Settings page whether those toggles also update the role directly; if so, the seeder should not touch those three permissions after the first run.)
- No new permission migrations for this feature. The `badge_code` column is still a migration (schema, not permissions). Existing permission migrations (e.g. `2026_11_09_130000_add_order_type_permissions.php`) stay as they are; their permissions are also in the catalog so the seeder covers fresh installs.

### Defaults (applied by the seeder when the permission is first created)

User decision: defaults match today's behaviour — every POS user currently sees every tab, so nobody loses a tab on deploy.

- Every role that holds `pos.access` at seed time (today: `admin`, `cashier`) gets every `pos.tab.type.{code}` and every `pos.tab.system.*` permission when that permission is first created.
- Types created later at runtime: their `pos.tab.type.{code}` is granted to every role that holds `pos.access` at that moment (plus `admin`), matching today where a new type tab is visible to all POS users.
- After that, the owner narrows access from the roles screen; the seeder never re-grants a revoked tab permission.

### Non-goals

- PIN / second factor on badges.
- Badge access to the Filament admin panel.
- Auto-logout on inactivity or after an order is completed (logout remains manual).
- "Scan to switch" while another user is still logged in — the flow is logout → scan.
- Changing the shift model (stays global).

### Anti-goals

- A cashier ends up locked out of tabs they use today right after deploy.
- Hidden tabs whose data is still sent to the browser.
- Badge login that can reach `/admin`.

### Left to the builder

- Barcode library choice (JS e.g. JsBarcode, or a PHP generator) and print layout.
- Badge code format — must be random, ≥12 chars, scanner-friendly (uppercase alphanumeric), unique.
- Throttle numbers.
- Whether manage-order routes should also reject orders whose type tab the user lacks (recommended: yes for `manage`, since otherwise a hidden tab's order can be opened by ID).

### Tests expected

- Pest: badge login success/failure/soft-deleted user/user without `pos.access`; badge session denied by `canAccessPanel`; password session still allowed; throttle.
- Pest: `orders.index` `tabs` prop and data omit tabs/orders/expenses the user lacks (including a forced-visible hidden tab); expense routes 403 without `pos.tab.system.expenses`; opening/changing to a type without its permission is refused.
- Pest: seeder is idempotent — second run changes nothing; a permission removed from `cashier` in the roles screen is not re-added; a newly introduced permission is granted to its default roles; orphaned `pos.tab.type.*` is deleted.
- Pest: creating an `OrderTypeRow` creates `pos.tab.type.{code}` and grants it to admin and every role with `pos.access`; deleting a custom type removes it; the roles screen lists the new tab permission.
- Filament: generate / regenerate / clear badge actions.

---

## Feature B — Order line audit and timing in the Filament order page

### Intent

Managers reviewing an order in the admin panel need to see what happened to it after it was opened: which items were added, increased, decreased or removed, when, and by whom; how long the order was open; and how the kitchen performed on it, per item.

### Current state (verified in code)

- `order_item_changes` table + `OrderItemChange` model already record every item change on save (`OrderService::saveOrder` → `OrderItemChangeService::recordChanges`): `change_type` (`added` | `removed` | `quantity_changed`), `old_quantity`, `new_quantity`, `delta`, `product_name`, timestamps. Notes-only changes are skipped. Initial save of a new order records all its items as `added`.
- **No `user_id`** on `order_item_changes` — who made the change is not recorded.
- `Order::itemChanges()` relation exists (newest first). Nothing in Filament displays it.
- Orders have only `created_at`/`updated_at` — **no completed/cancelled timestamps**. Completion: `OrderCompletionService` (line ~30) and `WebApiService` (~283); cancellation: `OrderService` (~173) and `WebApiService` (~252).
- KDS data: `kitchen_tickets` (`first_sent_at`, `seen_at`, `done_at`, `cancelled_at`, `cancel_acknowledged_at`), `kitchen_ticket_rounds` (`started_at`, `ended_at`), `kitchen_ticket_items` (`sent_at`, `done_at`, `voided_at` — no start step since commit `4107684`, `target_minutes`, `status`, `kind`, `kitchen_station_id`, `done_by`, `quantity`, `original_quantity`, `voided_after_done`, `auto_finished`).
- Filament order view: `app/Filament/Resources/Orders/OrderResource.php` infolist sections + `ItemsRelationManager`, `PaymentsRelationManager`, `OrderReturnsRelationManager`; `Pages/ViewOrder.php`.

### Behaviour

**1. Item changes ("سجل تعديلات الأصناف")** — new relation manager (read-only table) on the order view:
- Columns: time, product name, change type badge — added (أضيف) / increased (زيادة) / decreased (نقص) / removed (حذف) — old qty → new qty, delta (+green / −red), user.
- `quantity_changed` is displayed as increased or decreased by the sign of `delta` (no data migration needed).
- Default sort: oldest first (reads as a timeline). Filter by change type.
- New nullable `order_item_changes.user_id` (FK users, null on delete), filled with `auth()->id()` when recording. Old rows show "—".

**2. Order timing** — new infolist section "توقيت الطلب":
- Opened at (`created_at`), completed at, cancelled at, open duration (opened → completed/cancelled, or "مفتوح منذ …" if still open).
- New nullable `orders.completed_at`, `orders.cancelled_at`, set at every place status becomes completed/cancelled (POS and web). Old orders show "—" (no backfill).

**3. KDS timing** — new infolist section "توقيت المطبخ" (hidden when the order has no kitchen ticket):
- Ticket level: first sent, first seen, done, total kitchen time (first sent → done), cancelled/ack times when applicable.
- Rounds: each round's start/end (shows add-on rounds sent later).
- Item level table (relation manager or repeatable entry): product, station, kind (original/added/increased), qty, sent, done/voided, prep time (sent → done, matching the kitchen report), target minutes, over-target highlight (red when prep > target), status, done by, auto-finished/voided-after-done flags.
- Durations formatted as mm:ss / h:mm in Arabic UI.

### Non-goals

- Backfilling `user_id`, `completed_at`, `cancelled_at` for historical data.
- Recording notes-only changes.
- Any change to POS or KDS screens.
- Editing audit rows (read-only).

### Anti-goals

- Audit can be edited or deleted from the panel.
- N+1 queries on the order view (eager-load changes.user, ticket.rounds, ticket.items.station).

### Left to the builder

- Relation manager vs. infolist repeatable entry for KDS items (pick whichever reads best in Filament v4).
- Exact duration formatting helper.
- Permission for the new sections: reuse `view_orders`.

### Tests expected

- Pest: saving an order records `user_id` on changes; completing / cancelling (POS and web paths) sets the timestamps.
- Filament/Livewire: order view renders the item-changes relation manager with increased/decreased mapping; KDS section hidden without ticket and shows durations with ticket.
