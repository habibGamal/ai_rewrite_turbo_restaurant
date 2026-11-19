<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Filament\Pages\Reports\StockReport;
use App\Models\Product;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('inventory_status')]
#[Title('Stock levels')]
#[Description(<<<'TEXT'
Current stock right now: items at or below their minimum (low), stock levels with days of cover at the last 30 days' usage (levels, optionally for one product or category), stock value per warehouse (value), and items with stock but no sale for 30+ days (dead).
Use for "what is running low", "how much stock of X do we have", "how many days will the flour last", "stock value", "dead stock".
Not for what happened over a period (purchases, waste, losses: use inventory_movements).
Examples: «ما الأصناف التي قاربت على النفاد؟», «كم يكفي مخزون الدقيق من يوم؟», "Total stock value per warehouse".
TEXT)]
#[IsReadOnly]
class InventoryStatusTool extends BusinessTool
{
    public const VIEWS = ['low', 'levels', 'value', 'dead'];

    private const USAGE_DAYS = 30;

    protected function reportPages(): array
    {
        return [StockReport::class];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'view' => $schema->string()->enum(self::VIEWS)->description('Default low.'),
            'warehouse' => $schema->string()->description('Warehouse name or id. Default: all warehouses together.'),
            'product' => $schema->string()->description('For levels: one product (name or id).'),
            'category' => $schema->string()->description('For levels/low: one category (name or id).'),
            'limit' => $schema->integer()->description('Rows. Default 10, max 100.'),
        ];
    }

    protected function run(Request $request): array
    {
        $view = (string) ($request->get('view') ?? 'low');
        if (! in_array($view, self::VIEWS, true)) {
            throw new InvalidArgumentException('view must be one of: '.implode(', ', self::VIEWS).'.');
        }

        $applied = [];
        $warehouseId = null;
        if (($warehouse = $request->get('warehouse')) !== null && $warehouse !== '') {
            $model = $this->resolveModel(Warehouse::class, $warehouse, 'warehouse');
            $warehouseId = (int) $model->getKey();
            $applied['warehouse'] = $model->getAttribute('name');
        }

        if ($view === 'value') {
            return $this->envelope(null, ['warehouses' => $this->valueByWarehouse($warehouseId)], [
                'value' => 'Quantity × the product\'s current cost.',
            ], [], $applied);
        }

        $query = $this->levels($warehouseId);

        [$mainId, $subId, $categoryName] = $this->resolveCategory($request->get('category'));
        if ($subId !== null) {
            $query->where('p.category_id', $subId);
        } elseif ($mainId !== null) {
            $query->where(fn ($q) => $q->where('p.category_id', $mainId)->orWhereIn('p.category_id', DB::table('categories')->where('parent_id', $mainId)->select('id')));
        }
        if ($categoryName !== null) {
            $applied['category'] = $categoryName;
        }

        if (($product = $request->get('product')) !== null && $product !== '') {
            $model = $this->resolveModel(Product::class, $product, 'product');
            $query->where('p.id', $model->getKey());
            $applied['product'] = $model->getAttribute('name');
        }

        $limit = $this->limit($request);
        $caveats = [];

        match ($view) {
            'low' => $query->where('p.min_stock', '>', 0)->whereRaw('st.quantity <= p.min_stock')->orderByRaw('st.quantity / p.min_stock'),
            'dead' => $query->whereRaw('st.quantity > 0.0005')
                ->where(fn ($q) => $q->whereNull('u.last_sale')->orWhere('u.last_sale', '<', CarbonImmutable::now()->subDays(self::USAGE_DAYS)->toDateString()))
                ->orderByRaw('st.quantity * COALESCE(p.cost, 0) DESC'),
            default => $query->orderBy('p.name'),
        };

        $rows = $query->limit($limit)->get()->map(function ($row) {
            $usage = (float) ($row->usage_qty ?? 0);
            $daily = $usage / self::USAGE_DAYS;
            $qty = (float) $row->quantity;

            return [
                'product_id' => (int) $row->id,
                'product' => $row->name,
                'unit' => $row->unit,
                'quantity' => round($qty, 3),
                'min_stock' => $row->min_stock !== null ? (float) $row->min_stock : null,
                'value' => round($qty * (float) $row->cost, 2),
                'used_last_30_days' => round($usage, 3),
                'cover_days' => $daily > 0 ? round($qty / $daily, 1) : null,
                'last_sale' => $row->last_sale ? substr((string) $row->last_sale, 0, 10) : null,
            ];
        })->all();

        if ($view === 'low' && $rows === []) {
            $caveats[] = 'No item is at or below its minimum. Items without a minimum stock set are never listed as low.';
        }

        return $this->envelope(null, ['view' => $view, 'items' => $rows], [
            'quantity' => 'Stock now, in the product\'s unit.',
            'cover_days' => 'quantity / average daily usage over the last 30 days (sales, issues and manufacturing). null = no usage.',
            'dead' => 'Stock above zero and no sale in the last 30 days.',
        ], $caveats, $applied);
    }

    /** Stock per product now, with the last 30 days' usage. */
    private function levels(?int $warehouseId): QueryBuilder
    {
        $stock = DB::table('inventory_items')
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->groupBy('product_id')
            ->select('product_id')
            ->selectRaw('SUM(quantity) as quantity');

        $since = CarbonImmutable::now()->subDays(self::USAGE_DAYS - 1)->toDateString();
        $usage = DB::table('inventory_item_movements as m')
            ->when($warehouseId !== null, fn ($q) => $q->where('m.warehouse_id', $warehouseId))
            ->groupBy('m.product_id')
            ->select('m.product_id')
            ->selectRaw("SUM(CASE WHEN m.business_date >= ? AND m.reason IN ('order', 'stock_issue', 'manufacturing_out') AND m.operation = 'out' THEN m.quantity WHEN m.business_date >= ? AND m.reason = 'order_return' AND m.operation = 'in' THEN -m.quantity ELSE 0 END) as usage_qty", [$since, $since])
            ->selectRaw("MAX(CASE WHEN m.reason = 'order' AND m.operation = 'out' THEN m.business_date END) as last_sale");

        return DB::table('products as p')
            ->joinSub($stock, 'st', 'st.product_id', '=', 'p.id')
            ->leftJoinSub($usage, 'u', 'u.product_id', '=', 'p.id')
            ->select('p.id', 'p.name', 'p.unit', 'p.min_stock', 'p.cost', 'st.quantity', 'u.usage_qty', 'u.last_sale');
    }

    /** @return list<array{warehouse: string, items: int, value: float}> */
    private function valueByWarehouse(?int $warehouseId): array
    {
        return DB::table('inventory_items as i')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->join('warehouses as w', 'w.id', '=', 'i.warehouse_id')
            ->when($warehouseId !== null, fn ($q) => $q->where('i.warehouse_id', $warehouseId))
            ->where('i.quantity', '>', 0)
            ->groupBy('w.id', 'w.name')
            ->select('w.name')
            ->selectRaw('COUNT(DISTINCT i.product_id) as items, SUM(i.quantity * COALESCE(p.cost, 0)) as value')
            ->orderByDesc('value')
            ->get()
            ->map(fn ($row) => ['warehouse' => (string) $row->name, 'items' => (int) $row->items, 'value' => round((float) $row->value, 2)])
            ->all();
    }
}
