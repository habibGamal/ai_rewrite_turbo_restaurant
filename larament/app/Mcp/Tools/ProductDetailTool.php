<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\OrderStatus;
use App\Filament\Pages\Reports\ProductsSalesPerformanceReport;
use App\Filament\Pages\Reports\StockReport;
use App\Models\Product;
use App\Services\FeatureService;
use App\Services\Reports\SqlDates;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('product_detail')]
#[Title('Product detail')]
#[Description(<<<'TEXT'
Everything about one product: price, cost and unit margin, category, recipe components, sales in the period (quantity, value, profit, weekly trend) with the comparison period, returns, current stock and recent cost changes.
Use for "tell me about item X", "is X profitable", "how is X selling week by week", "what goes into X", "how much X is in stock".
Not for ranking many products (use product_performance). Resolve the name first with find_entities when unsure.
Examples: «ما هامش ربح الشاورما وكم بيع منها هذا الشهر؟», «ما مكونات وجبة الكفتة؟», "Weekly sales of the large pizza over the last 90 days".
TEXT)]
#[IsReadOnly]
class ProductDetailTool extends BusinessTool
{
    private readonly FeatureService $features;

    /** Services come from the container when the tool is built without them. */
    public function __construct(?FeatureService $features = null)
    {
        $this->features = $features ?? app(FeatureService::class);
    }

    protected function reportPages(): array
    {
        return [ProductsSalesPerformanceReport::class];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'product' => $schema->string()->required()->description('Product id or name.'),
            ...$this->periodSchema($schema, default: 'last_30_days'),
        ];
    }

    protected function run(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resolveModel(Product::class, $request->get('product'), 'product');
        $product->loadMissing('category');
        $period = $this->period($request, 'last_30_days');
        $comparison = $period->comparison();

        $price = (float) $product->price;
        $cost = (float) $product->cost;

        $current = $this->sales($product->id, $period->from->toDateString(), $period->to->toDateString());
        $previous = $comparison !== null ? $this->sales($product->id, $comparison->from->toDateString(), $comparison->to->toDateString()) : null;

        $data = [
            'product' => array_filter([
                'product_id' => $product->id,
                'name' => $product->name,
                'category' => $product->category?->getAttribute('name'),
                'type' => $product->type?->value,
                'unit' => $product->unit,
                'price' => round($price, 2),
                'cost' => round($cost, 2),
                'unit_margin' => $price > 0 ? round(($price - $cost) / $price * 100, 1) : null,
                'min_stock' => $product->getAttribute('min_stock') !== null ? (float) $product->getAttribute('min_stock') : null,
            ], fn ($v) => $v !== null),
            'sales' => $current,
            'previous' => $previous,
            'change_percent' => $previous !== null ? [
                'quantity' => self::change($current['quantity'], $previous['quantity']),
                'value' => self::change($current['value'], $previous['value']),
            ] : null,
            'weekly' => $period->days() > 7 ? $this->weekly($product->id, $period->from->toDateString(), $period->to->toDateString()) : null,
            'recipe' => $this->recipe($product->id),
            'cost_changes' => DB::table('product_cost_histories')->where('product_id', $product->id)->orderByDesc('id')->limit(5)
                ->get(['old_cost', 'new_cost', 'created_at'])
                ->map(fn ($row) => ['date' => substr((string) $row->created_at, 0, 10), 'old_cost' => round((float) $row->old_cost, 2), 'new_cost' => round((float) $row->new_cost, 2)])
                ->all(),
        ];

        if ($this->features->returnsEnabled()) {
            $data['returns'] = $this->returns($product->id, $period->from->toDateString(), $period->to->toDateString());
        }

        if (StockReport::canAccess()) {
            $stock = DB::table('inventory_items')->where('product_id', $product->id)->sum('quantity');
            $data['stock_now'] = round((float) $stock, 3);
        }

        return $this->envelope(
            $period,
            array_filter($data, fn ($v) => $v !== null && $v !== []),
            [
                'sales.value' => 'Item value in completed orders, before the order-level discount.',
                'sales.profit' => 'value − cost recorded on each sold line.',
                'unit_margin' => '(price − current cost) / price, percent.',
                'recipe' => 'Components used per one unit of this product.',
            ],
            [],
            [],
            $comparison !== null ? ['from' => $comparison->from->toDateString(), 'to' => $comparison->to->toDateString()] : null,
        );
    }

    /** @return array{orders: int, quantity: float, value: float, profit: float, avg_price: float} */
    private function sales(int $productId, string $from, string $to): array
    {
        $row = $this->lines($productId, $from, $to)
            ->selectRaw('COUNT(DISTINCT orders.id) as orders, COALESCE(SUM(order_items.quantity), 0) as qty, COALESCE(SUM(order_items.total), 0) as value')
            ->selectRaw('COALESCE(SUM(order_items.total - order_items.cost * order_items.quantity), 0) as profit')
            ->first();

        $qty = (float) ($row->qty ?? 0);

        return [
            'orders' => (int) ($row->orders ?? 0),
            'quantity' => round($qty, 3),
            'value' => round((float) ($row->value ?? 0), 2),
            'profit' => round((float) ($row->profit ?? 0), 2),
            'avg_price' => $qty > 0 ? round((float) $row->value / $qty, 2) : 0.0,
        ];
    }

    /** @return list<array{week: string, quantity: float, value: float}> */
    private function weekly(int $productId, string $from, string $to): array
    {
        $bucket = SqlDates::bucket('orders.created_at', 'week');

        return $this->lines($productId, $from, $to)
            ->selectRaw("{$bucket} as bucket, SUM(order_items.quantity) as qty, SUM(order_items.total) as value")
            ->groupByRaw($bucket)
            ->orderBy('bucket')
            ->get()
            ->map(fn ($row) => ['week' => (string) $row->bucket, 'quantity' => round((float) $row->qty, 3), 'value' => round((float) $row->value, 2)])
            ->all();
    }

    private function lines(int $productId, string $from, string $to): Builder
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.product_id', $productId)
            ->where('orders.status', OrderStatus::COMPLETED->value)
            ->whereBetween('orders.created_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()]);
    }

    /** @return list<array{component: string, quantity: float, unit: ?string}> */
    private function recipe(int $productId): array
    {
        return DB::table('product_components as pc')
            ->join('products as c', 'c.id', '=', 'pc.component_id')
            ->where('pc.product_id', $productId)
            ->get(['c.name', 'c.unit', 'pc.quantity'])
            ->map(fn ($row) => ['component' => (string) $row->name, 'quantity' => (float) $row->quantity, 'unit' => $row->unit])
            ->all();
    }

    /** @return array{quantity: float, refunded: float} */
    private function returns(int $productId, string $from, string $to): array
    {
        $row = DB::table('order_return_items as ori')
            ->join('order_returns as orr', 'orr.id', '=', 'ori.order_return_id')
            ->join('order_items as roi', 'roi.id', '=', 'ori.order_item_id')
            ->where('roi.product_id', $productId)
            ->whereBetween('orr.created_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])
            ->selectRaw('COALESCE(SUM(ori.quantity), 0) as qty, COALESCE(SUM(ori.refund_amount), 0) as amount')
            ->first();

        return ['quantity' => round((float) ($row->qty ?? 0), 3), 'refunded' => round((float) ($row->amount ?? 0), 2)];
    }
}
