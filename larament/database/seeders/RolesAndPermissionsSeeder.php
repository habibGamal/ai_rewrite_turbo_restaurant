<?php

namespace Database\Seeders;

use App\Models\Setting;
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
                'view_expenses_report' => 'تقرير المصروفات',
                'view_peak_hours_performance_report' => 'تقرير ساعات الذروة',
                'view_products_sales_performance_report' => 'تقرير مبيعات المنتجات',
                'view_products_sales_performance_shifts_report' => 'تقرير مبيعات المنتجات بالورديات',
                'view_stock_report' => 'تقرير المخزون',
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
        ];
    }

    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // 1. Create all permissions
        $allPermissionNames = [];
        foreach (self::permissionsCatalog() as $group => $permissions) {
            foreach ($permissions as $name => $label) {
                Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
                $allPermissionNames[] = $name;
            }
        }

        // 2. Create Roles
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $cashierRole = Role::firstOrCreate(['name' => 'cashier', 'guard_name' => 'web']);
        $kitchenRole = Role::firstOrCreate(['name' => 'kitchen', 'guard_name' => 'web']);
        $viewerRole = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
        $watcherRole = Role::firstOrCreate(['name' => 'watcher', 'guard_name' => 'web']);

        // 3. Assign all permissions to Admin
        $adminRole->syncPermissions($allPermissionNames);

        // 4. Assign permissions to Cashier
        $cashierPermissions = [
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
        ];

        // Check dynamic cashier settings if settings table exists
        if (Schema::hasTable('settings')) {
            $allowDiscounts = Setting::where('key', 'allow_cashier_discounts')->value('value');
            if ($allowDiscounts === '1' || $allowDiscounts === true || $allowDiscounts === 'true') {
                $cashierPermissions[] = 'order.apply_discount';
            }

            $allowCancel = Setting::where('key', 'allow_cashier_cancel_orders')->value('value');
            if ($allowCancel === '1' || $allowCancel === true || $allowCancel === 'true') {
                $cashierPermissions[] = 'order.cancel';
            }

            $allowItemChanges = Setting::where('key', 'allow_cashier_item_changes')->value('value');
            if ($allowItemChanges === '1' || $allowItemChanges === true || $allowItemChanges === 'true') {
                $cashierPermissions[] = 'order.change_items';
            }
        }

        $cashierRole->syncPermissions($cashierPermissions);

        // 5. Assign permissions to Kitchen
        $kitchenRole->syncPermissions(['kitchen.access']);

        // 6. Assign permissions to Viewer
        $reportPermissions = array_keys(self::permissionsCatalog()['التقارير']);
        $viewerRole->syncPermissions(array_merge(['access_admin_panel'], $reportPermissions));

        // 7. Assign permissions to Watcher
        $watcherPermissions = [
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
        ];
        $watcherRole->syncPermissions($watcherPermissions);
    }
}
