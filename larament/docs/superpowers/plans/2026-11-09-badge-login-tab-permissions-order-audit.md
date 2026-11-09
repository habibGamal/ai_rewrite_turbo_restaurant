# Badge Quick Login, POS Tab Permissions, and Order Line Audit — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Staff log into the POS by scanning a badge, every cashier-desk tab is permission-gated (tabs are dynamic), and the Filament order page shows item-level change audit, order timing and KDS timing.

**Architecture:** Feature B (audit) adds three nullable columns, fills them at the source (`OrderItemChangeService`, an `Order` saving hook) and adds read-only Filament sections. Feature A adds a `TabPermissions` service that maps `CashierTabLayout` keys to Spatie permissions; an additive, re-runnable `RolesAndPermissionsSeeder` (run by `deploy.sh`) and an `OrderTypeRow` observer keep those permissions in sync; a guest badge-login endpoint marks the session `auth_via_badge`, which `User::canAccessPanel()` refuses.

**Tech Stack:** Laravel 11, Filament v4, Spatie Permission, Inertia + React + Ant Design (TypeScript), Pest, `picqer/php-barcode-generator` (new).

**Spec:** `docs/superpowers/specs/2026-11-09-badge-login-tab-permissions-order-audit-design.md`

## Global Constraints

- App root: `D:\ai_rewrite_turbo_restaurant\larament` (AGENTS.md paths `e:\AI_rewirte\...` are stale). Never run `php artisan serve`.
- Arabic UI copy, RTL. Timezone `Africa/Cairo`.
- Permissions are created only by `RolesAndPermissionsSeeder` (deploy) and the `OrderTypeRow` observer (runtime). **No permission migrations.** Schema changes still use migrations.
- Tab permission names: `pos.tab.type.{code}` and `pos.tab.system.{receivables|expenses|cancelled|completed}`. Build them only through `TabPermissions`.
- Defaults match today: every role holding `pos.access` gets every tab permission when that permission is first created. The seeder never revokes and never re-grants a permission the owner removed.
- Badge login reaches the POS/KDS only, never the Filament panel. Badge error copy: `الكارت غير صالح`.
- Tests: Pest. `pest --parallel` crashes on this machine — run single files, and the full suite serially with `BROADCAST_CONNECTION=log`. The suite has pre-existing failures (baseline in `docs/orders/delegation-progress.md`); a task passes when its own tests pass and it adds no new failing test names / PHPStan errors vs. baseline.
- Each task ends with `./vendor/bin/pint` on touched files and one commit.

## Sequencing (other work in flight on `feature/order-types`)

- KDS items have no `started_at` / `started` status any more (commit `4107684`). Do not reference them.

- B1 (enum cast removal) is at its implementer; B3 (generic React tabs, `Orders/Index.tsx`, `ChangeOrderTypeModal`, `ManageOrder`, `tabs` Inertia prop) is queued after B1+B2.
- Tasks 1–7 do not touch B3's files and can start now. **Task 8 starts only after B3 is merged** into `feature/order-types`; it filters the `tabs` prop B3 introduces.
- Where `$order->type` is used: after B1 it may be a plain string code; use `$order->type instanceof \BackedEnum ? $order->type->value : $order->type` only if B1 is not yet merged when you work.

## Review Focus

1. `badge_code` leaking to the browser through `auth.user` (`HandleInertiaRequests` / `AddUserPermissions` call `$user->toArray()`) — it must be in `User::$hidden`. Test in Task 5.
2. Scanner input with surrounding whitespace or lowercase letters must still log in; random text must not. Test in Task 6.
3. A badge-session user who later logs in with a password must reach `/admin`; the badge flag must not survive into a password session. Test in Task 6.
4. Saving a role in the roles screen must not wipe its tab permissions (Edit/CreateRole `syncPermissions` only the groups in the form). Test in Task 4.
5. A KDS item still pending, or voided before done, must show `—` for prep time, never a negative or zero duration; an item marked done on a clock before `sent_at` must also show `—`. Test in Task 3.

---

### Task 1: Audit data — who changed items, when orders closed

**Files:**
- Create: `database/migrations/2026_11_09_150000_add_audit_columns_to_orders_and_item_changes.php`
- Modify: `app/Models/OrderItemChange.php`, `app/Services/OrderItemChangeService.php`, `app/Models/Order.php`
- Test: `tests/Feature/Orders/OrderAuditDataTest.php`

**Interfaces:**
- Produces: `order_item_changes.user_id` (nullable FK users, `nullOnDelete`), `OrderItemChange::user(): BelongsTo`; `orders.completed_at`, `orders.cancelled_at` (nullable timestamps, cast `datetime`).

- [ ] **Step 1: Write the failing tests**

```php
it('records the acting user on item changes', function () {
    $user = User::factory()->create(); actingAs($user);
    app(OrderItemChangeService::class)->recordChanges($order->id, [
        ['type' => 'added', 'product_id' => $product->id, 'product_name' => 'x', 'quantity' => 2],
    ]);
    expect(OrderItemChange::first()->user_id)->toBe($user->id);
});
it('records null user when no one is authenticated');
it('stamps completed_at when status becomes completed', function () {
    $order->update(['status' => OrderStatus::COMPLETED]);
    expect($order->fresh()->completed_at)->not->toBeNull();
});
it('stamps cancelled_at when status becomes cancelled');
it('does not overwrite completed_at on later saves', function () {
    // complete, travel 10 minutes, save another field → completed_at unchanged
});
it('leaves completed_at null for orders that are still processing');
```

- [ ] **Step 2: Run** `./vendor/bin/pest tests/Feature/Orders/OrderAuditDataTest.php` — expect FAIL (missing columns).
- [ ] **Step 3: Migration** adding the three columns (down drops them). Add `user_id` to `OrderItemChange::$fillable` + `user()` relation; `completed_at`/`cancelled_at` to `Order` fillable/casts.
- [ ] **Step 4: `OrderItemChangeService::recordChanges`** sets `'user_id' => auth()->id()` on each row.
- [ ] **Step 5: `Order::booted()` `saving` hook:** when `status` is dirty and becomes `completed` and `completed_at` is null → `now()`; same for `cancelled` / `cancelled_at`. Compare via the status value so it works whether `status` is an enum or string. This covers `OrderCompletionService`, `OrderService::cancelOrder` and `WebApiService` without editing them.
- [ ] **Step 6: Run** the test file — PASS. Run `tests/Feature/Orders` — no new failures.
- [ ] **Step 7: Commit** `feat(audit): record item-change user and order close timestamps`

---

### Task 2: Filament — item changes table and order timing

**Files:**
- Create: `app/Filament/Resources/Orders/RelationManagers/ItemChangesRelationManager.php`, `app/Support/Duration.php`
- Modify: `app/Filament/Resources/Orders/OrderResource.php` (infolist + `getRelations()`)
- Test: `tests/Feature/Filament/Resources/OrderAuditViewTest.php`, `tests/Unit/Support/DurationTest.php`

**Interfaces:**
- Consumes: Task 1 columns and `OrderItemChange::user()`.
- Produces: `App\Support\Duration::between(?CarbonInterface $from, ?CarbonInterface $to): ?string` — `null` if either is null or `$to < $from`; otherwise `m:ss` under one hour, `h:mm:ss` from one hour. Task 3 uses it.

- [ ] **Step 1: Failing tests**

```php
// Unit
expect(Duration::between($t, $t->copy()->addSeconds(75)))->toBe('1:15');
expect(Duration::between($t, $t->copy()->addSeconds(3725)))->toBe('1:02:05');
expect(Duration::between(null, $t))->toBeNull();
expect(Duration::between($t, $t->copy()->subMinute()))->toBeNull();

// Feature (admin, ViewOrder page)
livewire(ItemChangesRelationManager::class, ['ownerRecord' => $order, 'pageClass' => ViewOrder::class])
    ->assertCanSeeTableRecords($changes)
    ->assertTableColumnStateSet('change_label', 'زيادة', $increase)   // quantity_changed, delta > 0
    ->assertTableColumnStateSet('change_label', 'نقص', $decrease)     // quantity_changed, delta < 0
    ->assertTableColumnStateSet('change_label', 'أضيف', $added)
    ->assertTableColumnStateSet('change_label', 'حذف', $removed);
it('shows the user name and — for legacy rows without user');
it('has no create, edit or delete actions');
it('shows opened, completed and open-duration entries in the timing section');
```

- [ ] **Step 2: Run** both files — FAIL.
- [ ] **Step 3: `ItemChangesRelationManager`** (relationship `itemChanges`, title `سجل تعديلات الأصناف`): read-only (`isReadOnly(): true`, no header/record/bulk actions). Columns: `created_at` (dateTime), `product_name`, `change_label` (computed badge: added→`أضيف` success, removed→`حذف` danger, quantity_changed→`زيادة` info / `نقص` warning by sign of `delta`), `old_quantity`, `new_quantity`, `delta` (green `+` / red `−`), `user.name` (placeholder `—`). Default sort `created_at` asc (override the relation's desc with `->defaultSort('created_at', 'asc')` and `modifyQueryUsing(fn ($q) => $q->reorder())`). Filter by change type. Eager-load `user`.
- [ ] **Step 4: Infolist section `توقيت الطلب`** in `OrderResource::infolist`: `created_at` (فتح الطلب), `completed_at` (انتهاء الطلب, placeholder `—`), `cancelled_at` (إلغاء الطلب, visible when set), computed `open_duration` (مدة الطلب) = `Duration::between(created_at, completed_at ?? cancelled_at)`; when both null show `مفتوح منذ ` + `Duration::between(created_at, now())`.
- [ ] **Step 5:** Register the relation manager after `ItemsRelationManager`.
- [ ] **Step 6: Run** tests + `tests/Feature/Filament/Resources/OrderResourceTest.php` — PASS.
- [ ] **Step 7: Commit** `feat(audit): item change log and order timing on the order page`

---

### Task 3: Filament — KDS timing

**Files:**
- Create: `app/Filament/Resources/Orders/RelationManagers/KitchenItemsRelationManager.php`
- Modify: `app/Filament/Resources/Orders/OrderResource.php`, `app/Models/Order.php` (add `kitchenTicketItems(): HasManyThrough` via `KitchenTicket`)
- Test: `tests/Feature/Filament/Resources/OrderKdsTimingTest.php`

**Interfaces:**
- Consumes: `Duration::between` (Task 2); `Order::kitchenTicket()`; `KitchenTicket::rounds()`, `items()`; `KitchenTicketItem::station()`, `doneBy()`.

- [ ] **Step 1: Failing tests**

```php
it('hides the kitchen section when the order has no ticket');
it('shows ticket sent, seen, done and total kitchen time', function () {
    // first_sent_at 12:00:00, seen_at 12:00:30, done_at 12:14:00 → total '14:00'
});
it('lists each kitchen item with its prep time', function () {
    // sent 12:00, done 12:10 → prep '10:00'
});
it('flags an item that exceeded its target', function () {
    // target_minutes 5, prep 10:00 → over_target state true
});
it('shows — for the prep time of a pending or voided item', function () {
    // done_at null → prep '—'
});
it('shows voided time for voided items');
```

- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Infolist section `توقيت المطبخ`** (`->visible(fn ($record) => $record->kitchenTicket !== null)`): `kitchenTicket.first_sent_at`, `.seen_at`, `.done_at`, total (`Duration::between(first_sent_at, done_at)`), `.cancelled_at` / `.cancel_acknowledged_at` when set; a `RepeatableEntry` of `kitchenTicket.rounds` with `started_at` / `ended_at`.
- [ ] **Step 4: `KitchenItemsRelationManager`** (relationship `kitchenTicketItems`, title `توقيت أصناف المطبخ`, read-only, hidden via `canViewForRecord` when no ticket): columns `product_name`, `station.name`, `kind` (badge), `quantity`, `sent_at`, `done_at`, `voided_at`, `prep` = `Duration::between(sent_at, done_at)` (the KDS has no start step since commit `4107684`; prep is arrival in the kitchen → done, same as the kitchen report), `target_minutes`, `over_target` (danger icon when prep seconds > target×60), `status` badge, `doneBy.name`, `auto_finished` / `voided_after_done` icons. Placeholder `—` for nulls. Eager-load `station`, `doneBy`.
- [ ] **Step 5: Run** — PASS.
- [ ] **Step 6: Commit** `feat(audit): kitchen timing on the order page`

---

### Task 4: Tab permissions service, additive seeder, deploy step, roles screen

**Files:**
- Create: `app/Orders/TabPermissions.php`, `app/Observers/OrderTypeRowObserver.php`
- Modify: `app/Orders/CashierTabLayout.php` (make `SYSTEM_TABS` `public const`), `app/Models/OrderTypeRow.php` (`#[ObservedBy(OrderTypeRowObserver::class)]`), `database/seeders/RolesAndPermissionsSeeder.php`, `deploy.sh`, `app/Filament/Resources/Roles/RoleResource.php`, `.../Roles/Pages/CreateRole.php`, `.../Roles/Pages/EditRole.php`
- Test: `tests/Feature/Authorization/TabPermissionsTest.php`, `tests/Feature/Authorization/PermissionSeederTest.php`, `tests/Feature/Filament/Resources/RoleResourceTest.php`

**Interfaces:**
- Produces (`App\Orders\TabPermissions`, container-resolved):
  - `public const GROUP = 'تبويبات الكاشير';`
  - `public static function forKey(string $tabKey): string` — `type:dine_in` → `pos.tab.type.dine_in`, `system:expenses` → `pos.tab.system.expenses`; throws `InvalidArgumentException` for other prefixes.
  - `public static function forTypeCode(string $code): string`
  - `public function catalog(): array<string, string>` — permission ⇒ label for every `order_types` row (`'تبويب: '.$row->label`) and every system tab (`'تبويب: '.SYSTEM_TABS[name]['label']`); `[]` when `order_types` table is missing.
  - `public function sync(): list<string>` — creates missing catalog permissions (guard `web`), deletes `pos.tab.type.*` permissions with no matching row, resets the Spatie cache; returns names created.
  - `public function grantToPosRoles(array $permissionNames): void` — `givePermissionTo` on `admin` and every role that has `pos.access`.
  - `public function ensureForType(OrderTypeRow $row): void` — create + `grantToPosRoles` (used by observer `created`); `public function removeForType(OrderTypeRow $row): void` (observer `deleted`).
- `RolesAndPermissionsSeeder::permissionsCatalog()` keeps its static groups and adds `TabPermissions::GROUP => app(TabPermissions::class)->catalog()`.

- [ ] **Step 1: Failing tests**

```php
// TabPermissionsTest
expect(TabPermissions::forKey('type:dine_in'))->toBe('pos.tab.type.dine_in');
expect(TabPermissions::forKey('system:completed'))->toBe('pos.tab.system.completed');
it('creating an order type creates its permission for admin and every pos.access role');
it('deleting a custom order type removes its permission');
it('sync deletes orphaned type permissions and keeps system ones');

// PermissionSeederTest
it('fresh install gives cashier every tab permission and its default permissions');
it('second run changes nothing', function () {
    seed(); $before = snapshotRolePermissions(); seed();
    expect(snapshotRolePermissions())->toBe($before);
});
it('does not re-grant a tab permission the owner revoked', function () {
    seed(); Role::findByName('cashier')->revokePermissionTo('pos.tab.system.completed'); seed();
    expect(Role::findByName('cashier')->hasPermissionTo('pos.tab.system.completed'))->toBeFalse();
});
it('does not remove a permission the owner added to cashier', function () {
    // give cashier order.cancel, re-seed, still has it
});
it('grants a newly introduced permission to its default roles only once');
it('gives new tab permissions to every role holding pos.access, including custom roles');

// RoleResourceTest (Review Focus 4)
it('shows the cashier tabs group and keeps tab permissions when saving a role', function () {
    // role with pos.tab.type.dine_in; EditRole fill shows it checked; save unchanged → still has it
});
```

- [ ] **Step 2: Run** the three files — FAIL.
- [ ] **Step 3: Implement `TabPermissions`** and the observer per Interfaces.
- [ ] **Step 4: Rewrite `RolesAndPermissionsSeeder::run()`** as additive:
  1. Create static catalog permissions with `firstOrCreate`; collect names where `wasRecentlyCreated` into `$created`.
  2. `$created = [...$created, ...app(TabPermissions::class)->sync()]`.
  3. Roles via `firstOrCreate`; remember `wasRecentlyCreated` per role.
  4. `admin->syncPermissions(all catalog names)`.
  5. For `cashier`, `kitchen`, `viewer`, `watcher`: `givePermissionTo(role was just created ? $defaults : array_intersect($defaults, $created))`. Defaults are today's lists (cashier list incl. the three settings toggles read as today).
  6. `app(TabPermissions::class)->grantToPosRoles(tab permissions in $created)`.
  Never call `syncPermissions` on non-admin roles; never `revokePermissionTo`.
- [ ] **Step 5: `deploy.sh`**: right after `php artisan migrate --force` add `php artisan db:seed --class=RolesAndPermissionsSeeder --force` and `php artisan permission:cache-reset`.
- [ ] **Step 6: Roles screen**: new `Tab::make(TabPermissions::GROUP)` with `CheckboxList::make('permissions_pos_tabs')->options($catalog[TabPermissions::GROUP])->bulkToggleable()->dehydrated(false)`; fill it in `EditRole::mutateFormDataBeforeFill` and include `permissions_pos_tabs` in the merged list in both `CreateRole` and `EditRole` `afterSave`.
- [ ] **Step 7: Run** the three files + `tests/Feature/Authorization` + `OrderTypeRowResourceTest.php` — PASS.
- [ ] **Step 8: Commit** `feat(auth): per-tab POS permissions kept in sync by deploy seeder`

---

### Task 5: Badge codes on users — generate, print, clear

**Files:**
- Create: `database/migrations/2026_11_09_150100_add_badge_code_to_users_table.php`, `app/Http/Controllers/UserBadgeController.php`, `resources/views/print/user-badge.blade.php`
- Modify: `app/Models/User.php`, `app/Filament/Resources/Users/UserResource.php`, `routes/web.php`, `composer.json` (`composer require picqer/php-barcode-generator`)
- Test: `tests/Feature/Auth/UserBadgeTest.php`, `tests/Feature/Filament/Resources/UserResourceTest.php`

**Interfaces:**
- Produces: `users.badge_code` (string 32, nullable, unique); `User::generateBadgeCode(): string` (16 chars from `A–Z0–9`, retry on collision, saves); `User::clearBadgeCode(): void`; route `users.badge.print` = `GET /users/{user}/badge/print` (middleware `auth`, `permission:manage_users`).

- [ ] **Step 1: Failing tests**

```php
it('generates a 16-char uppercase alphanumeric unique code', fn () =>
    expect($user->generateBadgeCode())->toMatch('/^[A-Z0-9]{16}$/'));
it('regenerating replaces the old code');
it('never exposes badge_code in Inertia auth.user', function () {   // Review Focus 1
    $user->generateBadgeCode(); actingAs($user)->get(route('orders.index'))
        ->assertInertia(fn ($page) => $page->missing('auth.user.badge_code'));
});
it('prints the badge for managers and forbids others', function () {
    // manage_users → 200 and sees $user->name and an <svg; cashier → 403
});
// UserResourceTest
it('generate, clear actions update the code', fn () => livewire(ListUsers::class)
    ->callTableAction('generateBadge', $user)->assertHasNoTableActionErrors());
```

- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3:** Migration, `badge_code` in `$hidden` (not fillable), model methods.
- [ ] **Step 4: Filament record actions** on the users table: `generateBadge` (label `توليد كارت`, or `إعادة توليد الكارت` when a code exists, `requiresConfirmation()` when replacing), `printBadge` (`طباعة الكارت`, `url(route('users.badge.print', $record), shouldOpenInNewTab: true)`, visible when a code exists), `clearBadge` (`إلغاء الكارت`, confirmation, visible when a code exists).
- [ ] **Step 5: Print page**: `UserBadgeController::print(User $user)` renders the blade with the name (RTL), a Code 128 SVG from `Picqer\Barcode\BarcodeGeneratorSVG`, and the code text; `window.print()` on load; card-sized (`@page` ~ 86×54 mm).
- [ ] **Step 6: Run** — PASS.
- [ ] **Step 7: Commit** `feat(auth): staff badge codes with print and revoke`

---

### Task 6: Badge login screen and POS-only sessions

**Files:**
- Create: `app/Http/Controllers/Auth/BadgeLoginController.php`, `resources/js/Pages/Auth/BadgeLogin.tsx`
- Modify: `routes/auth.php`, `app/Models/User.php` (`canAccessPanel`), `app/Http/Controllers/Auth/AuthenticatedSessionController.php` (`store` forgets the flag), `app/Http/Middleware/HandleInertiaRequests.php` (share `auth.viaBadge`), `resources/js/Layouts/CashierLayout.tsx`, `resources/js/Components/Kitchen/KdsSettingsDrawer.tsx`, `resources/js/types/*` (add `viaBadge?: boolean` to the auth type)
- Test: `tests/Feature/Auth/BadgeLoginTest.php`

**Interfaces:**
- Consumes: `users.badge_code` (Task 5).
- Produces: routes (guest) `badge-login` = `GET /badge-login`, `badge-login.store` = `POST /badge-login` with `throttle:20,1`; session key `auth_via_badge` (bool); Inertia `auth.viaBadge`.

- [ ] **Step 1: Failing tests**

```php
it('logs a cashier in by badge and redirects to orders', function () {
    post(route('badge-login.store'), ['code' => $code])->assertRedirect(route('orders.index'));
    expect(session('auth_via_badge'))->toBeTrue();
});
it('redirects kitchen-role users to the kitchen screen');
it('accepts the code with surrounding whitespace and lowercase', fn () =>  // Review Focus 2
    post(route('badge-login.store'), ['code' => '  '.strtolower($code)."\n"])->assertRedirect(route('orders.index')));
it('rejects unknown codes, soft-deleted users and users without pos.access or kitchen.access', fn () =>
    post(route('badge-login.store'), ['code' => 'NOPE'])->assertSessionHasErrors(['code' => 'الكارت غير صالح']));
it('denies the admin panel to a badge session even for admins', fn () =>
    expect($admin->canAccessPanel(Filament::getPanel('admin')))->toBeFalse());
it('allows the panel after logout and password login', function () {  // Review Focus 3
    // badge login → post logout → password login via route('login') → canAccessPanel true, no auth_via_badge
});
it('throttles repeated attempts'); // 21st attempt in a minute → 429
```

- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Controller**: `create()` renders `Auth/BadgeLogin`; `store()` validates `code` `required|string|max:64`, normalises with `strtoupper(trim())`, finds the user by `badge_code`, requires `can('pos.access') || can('kitchen.access')`, else `back()->withErrors(['code' => 'الكارت غير صالح'])`. On success `Auth::login($user)`, `session()->regenerate()`, `session(['auth_via_badge' => true])`, redirect kitchen role → `kitchen.index`, otherwise `orders.index`.
- [ ] **Step 4: `User::canAccessPanel`** returns `$this->can('access_admin_panel') && ! session('auth_via_badge', false)`. `AuthenticatedSessionController::store` calls `session()->forget('auth_via_badge')` after regenerate.
- [ ] **Step 5: Frontend**: `BadgeLogin.tsx` — full-screen RTL, large prompt `امسح الكارت`, one input that is autofocused and refocuses on blur, submits on Enter via Inertia `useForm().post(route('badge-login.store'))`, clears and shows the error, link `الدخول بكلمة المرور` → `/login`. `CashierLayout` logout `onFinish` → `/badge-login`; hide the `الادارة` button when `auth.viaBadge`. `KdsSettingsDrawer` logout → `/badge-login`.
- [ ] **Step 6: Run** tests + `tests/Feature/Auth` — PASS; `npm run build` succeeds.
- [ ] **Step 7: Commit** `feat(auth): badge quick login for POS and kitchen`

---

### Task 7: Docs touch-up

Fold into Task 6's commit if trivial: add the deploy seeder line and the badge login flow to `docs/deployment.md` and `docs/features/` (one short paragraph each). No separate review gate.

---

### Task 8: Enforce tab permissions on the desk — **after B3 is merged**

**Files:**
- Create: `app/Http/Middleware/EnsureOrderTabAccess.php`
- Modify: `app/Models/User.php`, `app/Orders/CashierTabLayout.php`, `app/Http/Controllers/OrderController.php`, `bootstrap/app.php` (alias `order.tab`), `routes/web.php`, `resources/js/Pages/Orders/Index.tsx` (empty state only)
- Test: `tests/Feature/Orders/TabPermissionEnforcementTest.php`

**Interfaces:**
- Consumes: `TabPermissions::forKey` / `forTypeCode` (Task 4); B3's `tabs` Inertia prop and type-change endpoints.
- Produces: `User::canSeeTab(string $tabKey): bool`; `User::canAccessOrder(Order $order): bool` — true if the user can see `type:{code}`, or the order is completed and they can see `system:completed`, or cancelled and they can see `system:cancelled`; `CashierTabLayout::resolveFor(?Shift $shift, User $user): array` — `resolve()` filtered by `canSeeTab`, forced-visible tabs included.

- [ ] **Step 1: Failing tests**

```php
it('omits tabs the user lacks, including a forced-visible hidden tab', fn () =>
    actingAs($cashierWithoutDelivery)->get(route('orders.index'))
        ->assertInertia(fn ($p) => $p->where('tabs', fn ($tabs) => ! collect($tabs)->contains('key', 'type:delivery'))));
it('does not send orders of types the user cannot see');
it('does not send completed / cancelled orders without those tab permissions');
it('does not send expenses or expense types without pos.tab.system.expenses');
it('does not send receivables without pos.tab.system.receivables');
it('forbids expense routes without pos.tab.system.expenses', fn () =>
    actingAs($cashierWithoutExpenses)->post(route('expenses.store'), $payload)->assertForbidden());
it('refuses to open an order of a type the user cannot see');
it('refuses to change an order to a type the user cannot see');
it('forbids managing an order whose tab the user cannot see', fn () =>
    actingAs($cashierWithoutDelivery)->get(route('orders.manage', $deliveryOrder))->assertForbidden());
it('lets a user with only the completed tab open a completed order');
```

- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3:** `User::canSeeTab` / `canAccessOrder`; `CashierTabLayout::resolveFor`.
- [ ] **Step 4: `OrderController::index`**: `tabs` from `resolveFor`; orders filtered to (open and type allowed) or (completed and `system:completed`) or (cancelled and `system:cancelled`); expenses + expense types only with `system:expenses`; partial-paid/receivables only with `system:receivables`. The change-type options B3 sends are limited to types the user can see.
- [ ] **Step 5: Routes**: add `permission:pos.tab.system.expenses` to the `expenses` group; `EnsureOrderTabAccess` (aborts 403 unless `canAccessOrder` for the route-bound `{order}`) on every `orders/*/{order}` route; order creation checks `canSeeTab('type:'.$code)` for the requested type; type change checks the target type.
- [ ] **Step 6: `Index.tsx`**: when `tabs` is empty, show `لا توجد تبويبات متاحة لك` instead of the tab bar.
- [ ] **Step 7: Run** the file + `tests/Feature/Orders` — no new failures; `npm run build`.
- [ ] **Step 8: Commit** `feat(pos): enforce per-tab permissions on the cashier desk`
