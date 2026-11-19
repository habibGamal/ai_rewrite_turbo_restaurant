<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\AiQueryAccess;
use App\Mcp\Support\AiViews;
use App\Mcp\Support\ReadOnlyQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('describe_data_model')]
#[Title('Data model for direct queries')]
#[Description(<<<'TEXT'
The ai_* views that run_readonly_query can read: each view's columns with notes, how they join, and the rules for correct numbers (business date, completed orders, refunds).
Call it once before writing a query. Only for questions no other tool answers.
TEXT)]
#[IsReadOnly]
class DescribeDataModelTool extends BusinessTool
{
    protected function reportPages(): array
    {
        return [];
    }

    public function allowed(): bool
    {
        return AiQueryAccess::allowed();
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $request): array
    {
        AiViews::ensure();
        $views = [];
        foreach (AiViews::definitions() as $name => $view) {
            $columns = [];
            foreach (Schema::getColumnListing($name) as $column) {
                $columns[$column] = $view['columns'][$column] ?? '';
            }

            $views[] = ['view' => $name, 'description' => $view['description'], 'columns' => $columns];
        }

        return $this->envelope(null, [
            'views' => $views,
            'joins' => [
                'ai_order_items.order_id = ai_orders.id', 'ai_order_items.product_id = ai_products.id',
                'ai_products.category_id = ai_categories.id', 'ai_orders.customer_id = ai_customers.id',
                'ai_orders.staff_id = ai_staff.id', 'ai_orders.shift_id = ai_shifts.id',
                'ai_orders.sales_point_id = ai_sales_points.id', 'ai_orders.driver_id = ai_drivers.id',
                'ai_payments.order_id = ai_orders.id', 'ai_returns.order_id = ai_orders.id',
                'ai_return_items.return_id = ai_returns.id', 'ai_return_items.order_item_id = ai_order_items.id',
                'ai_stock.product_id = ai_products.id', 'ai_stock.warehouse_id = ai_warehouses.id',
                'ai_inventory_movements.product_id = ai_products.id', 'ai_purchase_invoices.supplier_id = ai_suppliers.id',
                'ai_purchase_invoice_items.purchase_invoice_id = ai_purchase_invoices.id',
            ],
            'rules' => [
                'Use the dedicated tools first; a direct query is for gaps only.',
                'Sales are orders with status = \'completed\'. Filter days on business_date, never created_at.',
                'Net sales = SUM(ai_orders.total) of completed orders − SUM(ai_returns.total_refund) by the refund\'s business_date.',
                'Item figures (ai_order_items.total) are before the order-level discount.',
                'Dates are YYYY-MM-DD strings in the shop\'s calendar.',
                'One SELECT (WITH allowed, no recursive CTEs), no comments. At most '.ReadOnlyQuery::MAX_ROWS.' rows come back, so aggregate in SQL.',
                'Write SQL that runs on both MySQL and SQLite: DATE(), SUBSTR(), COALESCE(), CASE, ROUND(); no DATE_FORMAT or strftime.',
            ],
        ]);
    }
}
