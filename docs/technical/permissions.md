# Roles & permissions

Permissions use **spatie/laravel-permission**. The catalogue, with Arabic labels, is `RolesAndPermissionsSeeder::permissionsCatalog()`; the admin edits roles on «الأدوار والصلاحيات».

The seeder is **additive and re-runnable** (deploy runs it every time): it creates missing permissions and roles, gives admin everything, and gives other roles only *newly introduced* defaults. It never revokes from a non-admin role, so the Roles screen stays the source of truth.

## Seeded roles

| Role | Default permissions |
|---|---|
| `admin` | Everything (except `vendor.*` and permissions of switched-off modules). |
| `cashier` | `pos.access`, `user.badge_login`, `shift.start`, `shift.end`, `terminal.open`, `terminal.close`, `order.create`, `order.complete`, `order.return`, `order.add_fees`, `order.add_equipment`, `customer.manage`, `driver.manage`, `cashier.open_drawer`, `web_order.manage`, `expense.create`, `invoice.print`, all cashier tab permissions. |
| `kitchen` | `kitchen.access` |
| `viewer` | Admin panel + all report permissions (including `view_inventory_movements_report`). |
| `watcher` | Admin panel + orders, customers, inventory items and a few main reports. |

## Cashier & terminal permissions worth knowing

| Permission | Arabic label |
|---|---|
| `user.badge_login` | الدخول لنقطة البيع بالبادج |
| `terminal.open` | فتح واستلام الدرج |
| `terminal.close` | قفل درجه |
| `terminal.close_others` | قفل درج كاشير تاني نيابةً عنه |
| `terminal.bind_device` | ربط الأجهزة بالأدراج |
| `view_terminals` / `create_terminals` / `update_terminals` | عرض / إضافة / تعديل الأدراج |
| `order.cancel` | إلغاء الطلبات |
| `order.apply_discount` | تطبيق خصم على الطلبات والأصناف |
| `order.change_items` | تعديل وحذف الأصناف بعد حفظ الطلب |
| `order.return` | مرتجع واستبدال من نقطة البيع |
| `order.return_without_restock` | مرتجع تالف (بدون إرجاع للمخزن) |
| `order.return_any_age` | مرتجع طلبات أقدم من الحد المسموح |
| `order.add_fees` / `order.remove_fees` | إضافة / حذف رسوم إضافية |
| `order.add_equipment` / `order.remove_equipment` | إضافة / تقليل معدات |
| `shift_money.manage` / `shift_money.edit_closed` | مصروفات وإيرادات الورديات / المغلقة |
| `view_inventory_movements_report` | تقرير حركة المخزون |

## Tab permissions

Each cashier tab is a permission, managed by `App\Orders\TabPermissions`:

- `pos.tab.type.<order type code>`: one per order type row (created and deleted with the rows).
- `pos.tab.system.<name>`: system tabs `receivables` (تحصيل الآجل), `expenses` (مصاريف), `cancelled` (ملغي), `completed` (منتهي).

New tab permissions go to every role that can use the POS.

## Module and feature gating

- Module permissions (`Module::permissions()`) are denied by `Gate::before` for **everyone** while the module is off and hidden on the Roles screen.
- Feature permissions (`OptionalFeature::permissions()`) follow the same rule for their feature.
- Admin bypasses policies in Filament, so **permission gating alone does not hide a screen**: module screens must also be listed in `ModuleScreens`.

## Legacy toggles

The old «صلاحيات الكاشير» settings (`allow_cashier_discounts`, `allow_cashier_cancel_orders`, `allow_cashier_item_changes`) were converted once into `order.apply_discount`, `order.cancel` and `order.change_items` on the cashier role, then deleted.
