# Retire legacy cashier toggles + per-tab POS permissions — Implementation Plan

**Goal:**
1. Remove the legacy "صلاحيات الكاشير" settings tab (3 toggles) and map its stored values onto Spatie permissions on deploy, so nobody's effective access changes unexpectedly.
2. Gate every cashier-desk tab (dynamic order-type tabs + system tabs) by its own Spatie permission, so an owner can stop a role from seeing/using certain order types.

**Builds on:** `docs/superpowers/plans/2026-11-09-badge-login-tab-permissions-order-audit.md` **Task 4** and **Task 8** (read both in full — they are the detailed spec for part 2). Spec: `docs/superpowers/specs/2026-11-09-badge-login-tab-permissions-order-audit-design.md` (Feature A, "Per-tab permissions" + "Permissions seeding" only — badge login is NOT in scope).

## Global constraints

- App root: `E:\AI_rewirte\larament`. Never run `php artisan serve`.
- Arabic UI copy, RTL.
- **The working tree has uncommitted work from another task** (SettingKey.php, Settings.php, OrderController.php, SettingsService.php, Orders/Index.tsx, ManageOrder.tsx, OrderItem.tsx, …). Never `git stash`, `git checkout --`, `git reset`, or revert anything you did not write. Edit on top of it. **Do not commit.**
- Permissions are created only by `RolesAndPermissionsSeeder` (run from `deploy.sh`) and the `OrderTypeRow` observer (runtime). **No permission migrations.**
- Tab permission names: `pos.tab.type.{code}` and `pos.tab.system.{receivables|expenses|cancelled|completed}`. Build them only through `App\Orders\TabPermissions`.
- Nobody loses access on deploy: every role holding `pos.access` (admin, cashier, any custom POS role) gets every tab permission when that permission is first created. The seeder never revokes and never re-grants something the owner removed.
- Tests: Pest. Run single files (`./vendor/bin/pest tests/Feature/X.php`); `--parallel` may crash on this machine — if so run the suite serially with `BROADCAST_CONNECTION=log`. Note any failures that already exist before your changes (run the affected files once before editing to get a baseline).
- Finish with `./vendor/bin/pint` on touched files, `./vendor/bin/phpstan analyse` (no new errors), `npm run build`.

---

## Part 1 — Retire the legacy "صلاحيات الكاشير" toggles

### Facts (verified)
- Runtime already uses Spatie only: `User::canCancelOrders()` → `order.cancel`, `canApplyDiscounts()` → `order.apply_discount`, `canChangeOrderItems()` → `order.change_items`; frontend checks `order.apply_discount` / `order.change_items` in `OrderItem.tsx`, `ManageOrder.tsx`, `utils/orderItemsReducer.ts`.
- The three settings (`allow_cashier_discounts`, `allow_cashier_cancel_orders`, `allow_cashier_item_changes`) are read **only** by `RolesAndPermissionsSeeder::run()` (lines ~246-262) and by unused `SettingsService::isCashier*Allowed()` methods. Toggling them in the UI does nothing today — dead UI.

### Mapping (legacy setting → Spatie permission, granted to role `cashier`)
| Setting key | Permission |
|---|---|
| `allow_cashier_discounts` | `order.apply_discount` |
| `allow_cashier_cancel_orders` | `order.cancel` |
| `allow_cashier_item_changes` | `order.change_items` |

Rule: setting truthy (`'1'`, `'true'`, `true`, `1`) → `givePermissionTo` on `cashier` (if that role exists). Falsy → do nothing (never revoke: the roles screen is already the source of truth and the owner may have granted it there deliberately). Then delete the three setting rows and call `Setting::invalidateCache()` (check the exact method name on `App\Models\Setting`). Because the rows are deleted, this is naturally one-shot and safe on every re-run.

### Steps
- [ ] **1.1** `RolesAndPermissionsSeeder`: add a private `migrateLegacyCashierToggles(): void` implementing the mapping above (guard `Schema::hasTable('settings')`). Call it in `run()` after roles exist and before role defaults are applied. Remove the old settings-reading block; cashier defaults no longer include the three permissions on a fresh install (matches today's fresh-install defaults, where the settings default to `false`).
- [ ] **1.2** `app/Filament/Pages/Settings.php`: delete the whole `Tabs\Tab::make('صلاحيات الكاشير')` block. Remove now-unused imports only if nothing else uses them.
- [ ] **1.3** `app/Enums/SettingKey.php`: remove the three cases and every `match` arm / list entry that references them (defaultValue, rules, label, helperText, placeholder, validate, isBooleanSetting list, …).
- [ ] **1.4** `app/Services/SettingsService.php`: remove `isCashierDiscountsAllowed`, `isCashierCancelOrdersAllowed`, `isCashierItemChangesAllowed`. Grep the whole repo (app, resources/js, tests, routes) for any remaining reference to the cases/keys/methods and remove or replace it. Leave old migration `2025_08_31_222256_add_cashier_permission_settings_to_settings_table.php` untouched.
- [ ] **1.5** Tests:
  - `tests/Feature/Filament/Pages/SettingsPageTest.php`: drop assertions/data for the three keys (lines ~118-120, 150-152, 163-170, 214-227, 375-377, 404); add one test asserting the three fields no longer exist on the page.
  - `tests/Feature/Features/OptionalFeatureGatingTest.php` ~441: switch to another boolean setting key.
  - New `tests/Feature/Authorization/LegacyCashierTogglesTest.php`:
    - setting `true` + cashier lacking the permission → after seeding, cashier has it; setting row deleted.
    - setting `false` + cashier already holding the permission (granted via roles screen) → still has it after seeding.
    - all three keys covered (dataset).
    - second seeder run is a no-op.

---

## Part 2 — Per-tab POS permissions

Implement **Task 4** then **Task 8** of the 2026-11-09 plan exactly as written there, with these adjustments:

- **Skip** anything about badges / `auth_via_badge` (Tasks 5-7 are out of scope).
- Task 4, Step 4 (additive seeder), cashier defaults: "the three settings toggles read as today" no longer applies — Part 1 replaces that. **Cashier defaults on a just-created role must include every current tab permission** (from `TabPermissions::catalog()`), because on a fresh install the observer may create type permissions during migrations, before roles exist, so they will not appear in the seeder's `$created` list.
- Task 4 seeder must stay idempotent with Part 1's step in it (second run changes nothing).
- Task 4 Step 5 (`deploy.sh`): add after `php artisan migrate --force`:
  ```sh
  php artisan db:seed --class=RolesAndPermissionsSeeder --force
  php artisan permission:cache-reset
  ```
- Task 4 Step 6 (roles screen): the new CheckboxList is `permissions_pos_tabs`. Review focus: saving a role from the roles screen must NOT wipe its tab permissions — both `CreateRole::afterSave` and `EditRole::afterSave` merge `permissions_pos_tabs`, and `EditRole::mutateFormDataBeforeFill` fills it. Check `FeatureService::filterPermissionCatalog` / `preserveHiddenRolePermissions` still behave with the new group.
- Task 4 observer: also handle `updated` when `code` changed (rename the permission, keep role grants) — or confirm in `OrderTypeRowResource` that `code` is not editable after create and skip.
- Task 8 (B3 is already merged — `tabs` prop, `CashierTabLayout`, `OrderTypeTab`, `ChangeOrderTypeModal` exist). Admin bypass already exists via `Gate::before` in `AppServiceProvider` (admin → true).
- Task 8, order filtering in `OrderController::index`: type tabs filter orders by `presentation.statuses` (see `resources/js/Components/Orders/Index/OrderTypeTab.tsx`), so some type tabs show completed/cancelled orders themselves. Send an order iff:
  - user can see `type:{order.type}` AND (order is open, OR its status is in that type's `presentation.statuses`), OR
  - order is `completed` and user can see `system:completed`, OR
  - order is `cancelled` and user can see `system:cancelled`.
  `User::canAccessOrder()` uses the same rule (type allowed, or completed+system:completed, or cancelled+system:cancelled).
- Task 8: also limit `changeTypeOptions()` (used by `manage`) to types the user can see, and check the target type in `createOrder()` and `updateOrderType()` (403 / back with error `غير مسموح لك بهذا النوع من الطلبات`).
- Task 8, `EnsureOrderTabAccess` middleware: apply to every route in the `orders.` group that binds `{order}` (manage, external.*, save, complete, collectRemaining, cancel, update*, applyDiscount, link*) and to `print` (`/orders/print/{order}`). Register the alias where this app registers middleware aliases (`bootstrap/app.php`).
- Task 8, expenses: add `permission:pos.tab.system.expenses` to the `expenses` route group in `routes/web.php`.
- Existing tests that log in as a cashier and hit `orders.*` / `expenses.*` may now fail because their user lacks tab permissions. Fix them by seeding properly (`RolesAndPermissionsSeeder`) or granting the needed tab permission — never by weakening enforcement.

---

## Done when

- [ ] Settings page has no "صلاحيات الكاشير" tab; no code references the three legacy keys outside the old 2025 migration.
- [ ] `RolesAndPermissionsSeeder` is additive + idempotent, maps legacy toggles, creates/cleans tab permissions; `deploy.sh` runs it.
- [ ] Roles screen has a "تبويبات الكاشير" group listing every order type (incl. newly created ones) + the 4 system tabs, and saving a role keeps them.
- [ ] Creating an order type at runtime creates `pos.tab.type.{code}` and grants it to admin + every role with `pos.access`; deleting it removes the permission.
- [ ] Desk: tabs, orders, receivables, expenses filtered by permission; per-order routes, create, change-type, expenses routes enforced; empty state `لا توجد تبويبات متاحة لك`.
- [ ] New tests pass; touched existing test files pass; no new PHPStan errors; Pint clean; `npm run build` passes.
- [ ] Nothing committed.
