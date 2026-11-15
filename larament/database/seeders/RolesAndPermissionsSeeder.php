<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Orders\TabPermissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * All permissions categorized by domain.
     *
     * @return array<string, array<string, string>>
     */
    public static function permissionsCatalog(): array
    {
        return [
            'إدارة النظام' => [
                'access_admin_panel' => 'الدخول للوحة تحكم المدير (Admin Panel)',
                'manage_settings' => 'إدارة إعدادات النظام',
                'manage_branch' => 'إدارة الفرع',
                'manage_excel_import' => 'استيراد البيانات من إكسل',
                'manage_users' => 'إدارة المستخدمين',
                'manage_roles' => 'إدارة الأدوار والصلاحيات',
            ],
            'إدارة المنتجات والقوائم' => [
                'view_categories' => 'عرض الفئات',
                'create_categories' => 'إضافة فئة',
                'update_categories' => 'تعديل فئة',
                'delete_categories' => 'حذف فئة',

                'view_products' => 'عرض المنتجات',
                'create_products' => 'إضافة منتج',
                'update_products' => 'تعديل منتج',
                'delete_products' => 'حذف منتج',

                'view_table_types' => 'عرض الطاولات وأنواعها',
                'create_table_types' => 'إضافة طاولة',
                'update_table_types' => 'تعديل طاولة',
                'delete_table_types' => 'حذف طاولة',

                'view_regions' => 'عرض المناطق وتكلفة التوصيل',
                'create_regions' => 'إضافة منطقة',
                'update_regions' => 'تعديل منطقة',
                'delete_regions' => 'حذف منطقة',

                'view_printers' => 'عرض الطابعات',
                'create_printers' => 'إضافة طابعة',
                'update_printers' => 'تعديل طابعة',
                'delete_printers' => 'حذف طابعة',

                'view_kitchen_stations' => 'عرض محطات المطبخ',
                'create_kitchen_stations' => 'إضافة محطة مطبخ',
                'update_kitchen_stations' => 'تعديل محطة مطبخ',
                'delete_kitchen_stations' => 'حذف محطة مطبخ',

                'view_order_types' => 'عرض أنواع الطلبات',
                'create_order_types' => 'إضافة نوع طلب',
                'update_order_types' => 'تعديل نوع طلب',
                'delete_order_types' => 'حذف نوع طلب',

                'view_external_sellers' => 'عرض المتاجر المتعاونة والطلبات الخارجية',
                'create_external_sellers' => 'إضافة متجر متعاون',
                'update_external_sellers' => 'تعديل متجر متعاون وتسوية الحسابات',
                'delete_external_sellers' => 'حذف متجر متعاون',
            ],
            'المشتريات والمخزون' => [
                'view_suppliers' => 'عرض الموردين',
                'create_suppliers' => 'إضافة مورد',
                'update_suppliers' => 'تعديل مورد',
                'delete_suppliers' => 'حذف مورد',

                'view_purchase_invoices' => 'عرض فواتير المشتريات',
                'create_purchase_invoices' => 'إضافة فاتورة مشتريات',
                'update_purchase_invoices' => 'تعديل فاتورة مشتريات',
                'delete_purchase_invoices' => 'حذف فاتورة مشتريات',

                'view_return_purchase_invoices' => 'عرض مرتجعات المشتريات',
                'create_return_purchase_invoices' => 'إضافة مرتجع مشتريات',
                'update_return_purchase_invoices' => 'تعديل مرتجع مشتريات',
                'delete_return_purchase_invoices' => 'حذف مرتجع مشتريات',

                'view_warehouses' => 'عرض المخازن',
                'create_warehouses' => 'إضافة مخزن',
                'update_warehouses' => 'تعديل مخزن',
                'delete_warehouses' => 'حذف مخزن',

                'view_inventory_items' => 'عرض عناصر المخزون',
                'create_inventory_items' => 'إضافة عنصر مخزون',
                'update_inventory_items' => 'تعديل عنصر مخزون',
                'delete_inventory_items' => 'حذف عنصر مخزون',

                'view_stocktakings' => 'عرض عمليات الجرد',
                'create_stocktakings' => 'إضافة جرد',
                'update_stocktakings' => 'تعديل جرد',
                'delete_stocktakings' => 'حذف جرد',

                'view_wastes' => 'عرض الهوالك',
                'create_wastes' => 'تسجيل هالك',
                'update_wastes' => 'تعديل هالك',
                'delete_wastes' => 'حذف هالك',

                'view_manufacturing_recipes' => 'عرض وصفات التصنيع',
                'create_manufacturing_recipes' => 'إضافة وصفة تصنيع',
                'update_manufacturing_recipes' => 'تعديل وصفة تصنيع',
                'delete_manufacturing_recipes' => 'حذف وصفة تصنيع',

                'view_manufacturing_orders' => 'عرض أوامر التصنيع',
                'create_manufacturing_orders' => 'إضافة أمر تصنيع',
                'update_manufacturing_orders' => 'تعديل وإكمال أمر تصنيع',
                'delete_manufacturing_orders' => 'حذف أمر تصنيع',

                'view_stock_transfers' => 'عرض أذون التحويل',
                'create_stock_transfers' => 'إضافة إذن تحويل',
                'update_stock_transfers' => 'تعديل وتأكيد إذن تحويل',
                'delete_stock_transfers' => 'حذف إذن تحويل',

                'view_stock_issues' => 'عرض أذون الصرف',
                'create_stock_issues' => 'إضافة إذن صرف',
                'update_stock_issues' => 'تعديل إذن صرف',
                'delete_stock_issues' => 'حذف إذن صرف',
            ],
            'العملاء والسائقين والمصروفات' => [
                'view_customers' => 'عرض العملاء',
                'create_customers' => 'إضافة عميل',
                'update_customers' => 'تعديل عميل',
                'delete_customers' => 'حذف عميل',

                'view_drivers' => 'عرض السائقين',
                'create_drivers' => 'إضافة سائق',
                'update_drivers' => 'تعديل سائق',
                'delete_drivers' => 'حذف سائق',

                'view_expenses' => 'عرض المصروفات في الإدارة',
                'create_expenses' => 'إضافة مصروف في الإدارة',
                'update_expenses' => 'تعديل مصروف في الإدارة',
                'delete_expenses' => 'حذف مصروف في الإدارة',

                'view_expense_types' => 'عرض بنود المصروفات',
                'create_expense_types' => 'إضافة بند مصروف',
                'update_expense_types' => 'تعديل بند مصروف',
                'delete_expense_types' => 'حذف بند مصروف',

                'view_orders' => 'عرض الطلبات في الإدارة',
                'create_orders' => 'إضافة طلب في الإدارة',
                'update_orders' => 'تعديل طلب في الإدارة',
                'delete_orders' => 'حذف طلب في الإدارة',

                'view_order_returns' => 'عرض مرتجعات الطلبات',
                'create_order_returns' => 'إضافة مرتجع طلب',
                'update_order_returns' => 'تعديل مرتجع طلب',
                'delete_order_returns' => 'حذف مرتجع طلب',
            ],
            'التقارير' => [
                'view_reports' => 'عرض كافة التقارير',
                'view_current_shift_report' => 'تقرير الوردية الحالية (اليوم)',
                'view_period_shift_report' => 'تقرير الورديات لفترة',
                'view_shift_logs_report' => 'تقرير سجلات الورديات',
                'view_daily_comparison_report' => 'تقرير المقارنة اليومية',
                'view_channel_performance_report' => 'تقرير أداء قنوات البيع',
                'view_customers_performance_report' => 'تقرير أداء العملاء',
                'view_driver_performance_report' => 'تقرير أداء السائقين',
                'view_cashier_commission_report' => 'تقرير عمولات الكاشير',
                'view_expenses_report' => 'تقرير المصروفات',
                'view_peak_hours_performance_report' => 'تقرير ساعات الذروة',
                'view_products_sales_performance_report' => 'تقرير مبيعات المنتجات',
                'view_products_sales_performance_shifts_report' => 'تقرير مبيعات المنتجات بالورديات',
                'view_stock_report' => 'تقرير المخزون',
                'view_purchases_report' => 'تقرير المشتريات',
                'view_waste_report' => 'تقرير الهالك',
                'view_stocktaking_report' => 'تقرير الجرد',
                'view_stock_issues_report' => 'تقرير أذون الصرف',
                'view_transfers_report' => 'تقرير التحويلات',
                'view_manufacturing_yield_report' => 'تقرير التصنيع والهالك الإنتاجي',
                'view_cost_history_report' => 'سجل تغيير التكلفة',
                'view_item_ledger_report' => 'كشف حركة صنف',
                'view_movement_summary_report' => 'ملخص حركة المخزون',
                'view_web_orders_report' => 'تقرير طلبات الويب',
            ],
            'نقطة البيع والعمليات (POS)' => [
                'pos.access' => 'الدخول لنقطة البيع (POS)',
                'shift.start' => 'فتح وردية جديدة',
                'shift.end' => 'إنهاء الوردية',
                'order.create' => 'إنشاء وحفظ الطلبات',
                'order.complete' => 'محاسبة وإنهاء الطلب',
                'order.cancel' => 'إلغاء الطلبات',
                'order.apply_discount' => 'تطبيق خصم على الطلبات والأصناف',
                'order.change_items' => 'تعديل وحذف الأصناف بعد حفظ الطلب',
                'customer.manage' => 'إضافة وربط العملاء بالطلب',
                'driver.manage' => 'إضافة وربط السائقين بالطلب',
                'cashier.open_drawer' => 'فتح درج النقدية',
                'web_order.manage' => 'إدارة وتوصيل طلبات الويب',
                'expense.create' => 'تسجيل مصروف بالوردية',
                'expense.delete' => 'حذف مصروف بالوردية',
                'inventory.toggle_day' => 'فتح وإغلاق يوم المخزون',
                'invoice.print' => 'طباعة الفواتير',
            ],
            'شاشة المطبخ' => [
                'kitchen.access' => 'الدخول والتفاعل مع شاشة المطبخ',
            ],
            TabPermissions::GROUP => app(TabPermissions::class)->catalog(),
        ];
    }

    /**
     * Legacy "صلاحيات الكاشير" settings => the Spatie permission they used to drive.
     *
     * @var array<string, string>
     */
    private const LEGACY_CASHIER_TOGGLES = [
        'allow_cashier_discounts' => 'order.apply_discount',
        'allow_cashier_cancel_orders' => 'order.cancel',
        'allow_cashier_item_changes' => 'order.change_items',
    ];

    /**
     * Additive and re-runnable: permissions and roles are created when missing, role
     * defaults are applied only to roles created now or to permissions created now, and
     * nothing is ever revoked from a non-admin role (the roles screen is the source of truth).
     */
    public function run(): void
    {
        $registrar = app()[PermissionRegistrar::class];
        $registrar->forgetCachedPermissions();

        $tabPermissions = app(TabPermissions::class);

        // 1. Create all catalog permissions, remembering which ones are new
        $allPermissionNames = [];
        $created = [];
        foreach (self::permissionsCatalog() as $permissions) {
            foreach (array_keys($permissions) as $name) {
                $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
                $allPermissionNames[] = $name;

                if ($permission->wasRecentlyCreated) {
                    $created[] = $name;
                }
            }
        }

        // 2. Tab permissions: create missing ones, delete orphaned order-type ones
        $tabCatalog = array_keys($tabPermissions->catalog());
        $created = array_values(array_unique([...$created, ...$tabPermissions->sync()]));

        // 3. Create roles
        $roles = [];
        $newRoles = [];
        foreach (['admin', 'cashier', 'kitchen', 'viewer', 'watcher'] as $name) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $roles[$name] = $role;
            $newRoles[$name] = $role->wasRecentlyCreated;
        }

        // 4. Carry the retired cashier settings over to permissions (never revokes)
        $this->migrateLegacyCashierToggles($roles['cashier']);

        // 5. Admin holds every permission
        $roles['admin']->syncPermissions($allPermissionNames);

        // 6. Role defaults: full list for new roles, only newly introduced permissions otherwise
        $defaults = [
            'cashier' => [
                'pos.access',
                'shift.start',
                'shift.end',
                'order.create',
                'order.complete',
                'customer.manage',
                'driver.manage',
                'cashier.open_drawer',
                'web_order.manage',
                'expense.create',
                'invoice.print',
                ...$tabCatalog,
            ],
            'kitchen' => ['kitchen.access'],
            'viewer' => ['access_admin_panel', ...array_keys(self::permissionsCatalog()['التقارير'])],
            'watcher' => [
                'access_admin_panel',
                'view_orders',
                'view_customers',
                'view_inventory_items',
                'view_reports',
                'view_current_shift_report',
                'view_period_shift_report',
                'view_daily_comparison_report',
                'view_products_sales_performance_report',
                'view_stock_report',
            ],
        ];

        foreach ($defaults as $name => $permissions) {
            $grant = $newRoles[$name] ? $permissions : array_values(array_intersect($permissions, $created));

            if ($grant !== []) {
                $roles[$name]->givePermissionTo($grant);
            }
        }

        // 7. Newly created tab permissions go to every role that can use the POS
        $tabPermissions->grantToPosRoles(array_values(array_intersect($created, $tabCatalog)));

        $registrar->forgetCachedPermissions();
    }

    /**
     * Grant the permission behind each enabled legacy toggle to the cashier role, then drop
     * the settings rows. Disabled toggles change nothing. Deleting the rows makes this one-shot.
     */
    private function migrateLegacyCashierToggles(Role $cashier): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $settings = Setting::query()
            ->whereIn('key', array_keys(self::LEGACY_CASHIER_TOGGLES))
            ->get();

        foreach ($settings as $setting) {
            if (filter_var($setting->value, FILTER_VALIDATE_BOOLEAN)) {
                $cashier->givePermissionTo(self::LEGACY_CASHIER_TOGGLES[$setting->key]);
            }

            $setting->delete();
        }
    }
}
