<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Filament\Pages\Reports\InventoryMovementsReport;
use App\Mcp\Support\Period;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Reports\Inventory\InventoryInsightsReportService;
use App\Services\Reports\Inventory\InventoryMovementsReportService;
use App\Services\Reports\Inventory\InventoryReportFilters;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('inventory_movements')]
#[Title('Inventory movements and losses')]
#[Description(<<<'TEXT'
Stock movement over a period, valued at cost: opening and closing stock value, purchases, consumption by sales, waste, stocktaking shortage/surplus, transfers and the loss rate (summary); or the items with the biggest losses, waste by reason, slow-moving items, purchases per supplier, cost price changes, and data alerts (negative balances, zero-cost movements, large count differences).
Use for "how much did we waste this month", "what is our loss rate", "which items have the biggest shortage", "which supplier did we buy most from", "which costs went up", "what is not moving".
Not for current stock levels or what is running low (use inventory_status).
Examples: «كم بلغ الهالك هذا الشهر وما أسبابه؟», «ما الأصناف التي ارتفعت تكلفتها؟», "Stock value at the start and end of last month".
TEXT)]
#[IsReadOnly]
class InventoryMovementsTool extends BusinessTool
{
    public const VIEWS = ['summary', 'losses', 'waste_reasons', 'slow_moving', 'suppliers', 'cost_changes', 'alerts'];

    private readonly InventoryMovementsReportService $movements;

    private readonly InventoryInsightsReportService $insights;

    /** Services come from the container when the tool is built without them. */
    public function __construct(?InventoryMovementsReportService $movements = null, ?InventoryInsightsReportService $insights = null)
    {
        $this->movements = $movements ?? app(InventoryMovementsReportService::class);
        $this->insights = $insights ?? app(InventoryInsightsReportService::class);
    }

    protected function reportPages(): array
    {
        return [InventoryMovementsReport::class];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'view' => $schema->string()->enum(self::VIEWS)->description('Default summary.'),
            ...$this->periodSchema($schema, default: 'last_30_days'),
            'warehouse' => $schema->string()->description('Warehouse name or id. Default: all.'),
            'category' => $schema->string()->description('Category name or id.'),
            'product' => $schema->string()->description('Product name or id.'),
            'limit' => $schema->integer()->description('Rows for list views. Default 10, max 100.'),
        ];
    }

    protected function run(Request $request): array
    {
        $view = (string) ($request->get('view') ?? 'summary');
        if (! in_array($view, self::VIEWS, true)) {
            throw new InvalidArgumentException('view must be one of: '.implode(', ', self::VIEWS).'.');
        }

        $period = $this->period($request, 'last_30_days');
        [$filters, $applied] = $this->filters($period, $request);
        $limit = $this->limit($request);

        $data = match ($view) {
            'summary' => $this->summary($filters),
            'losses' => ['items' => $this->insights->topLossItems($filters, $limit)],
            'waste_reasons' => ['reasons' => $this->insights->wasteReasons($filters)],
            'slow_moving' => ['items' => $this->insights->slowMoving($filters, $limit)],
            'suppliers' => ['suppliers' => array_slice($this->insights->suppliers($filters), 0, $limit)],
            'cost_changes' => ['items' => $this->insights->costChanges($filters, $limit)],
            default => ['alerts' => array_map(fn (array $alert) => [
                'check' => $alert['key'],
                'title' => $alert['title'],
                'found' => $alert['count'],
                'examples' => $alert['rows'],
            ], $this->insights->alerts($filters))],
        };

        $comparison = $filters->comparison();

        return $this->envelope(
            $period,
            $data,
            [
                'values' => 'At the cost recorded on each movement, so past figures do not change when a cost changes.',
                'consumption' => 'Stock used by sales (net of sales returns put back).',
                'loss' => 'waste + stocktaking shortage − stocktaking surplus.',
                'loss_rate' => 'loss / (consumption + stock issues + loss), percent.',
                'cover_days' => 'How many days the closing stock lasts at the period\'s daily usage.',
                'period' => 'Business dates of the movements.',
            ],
            [],
            $applied,
            $view === 'summary' && $comparison !== null ? ['from' => $comparison->startDate(), 'to' => $comparison->endDate()] : null,
        );
    }

    /** @return array<string, mixed> */
    private function summary(InventoryReportFilters $filters): array
    {
        $current = $this->movements->summary($filters);
        $comparison = $filters->comparison();
        $previous = $comparison !== null ? $this->movements->summary($comparison) : null;

        $change = [];
        if ($previous !== null) {
            foreach (['purchases', 'consumption', 'waste', 'loss', 'closing'] as $key) {
                $change[$key] = self::change((float) $current[$key], $previous[$key] !== null ? (float) $previous[$key] : null);
            }
        }

        return array_filter([
            'current' => $current,
            'previous' => $previous,
            'change_percent' => $change === [] ? null : $change,
            'by_type' => array_map(fn (array $row) => [
                'type' => $row['key'],
                'label' => $row['label'],
                'direction' => $row['direction'],
                'value' => $row['value'],
                'documents' => $row['documents'],
                'share' => $row['share'],
                'change_percent' => $row['change'],
            ], $this->movements->typeRows($filters)),
        ], fn ($value) => $value !== null);
    }

    /** @return array{0: InventoryReportFilters, 1: array<string, mixed>} */
    private function filters(Period $period, Request $request): array
    {
        $applied = [];
        $warehouseId = null;
        if (($warehouse = $request->get('warehouse')) !== null && $warehouse !== '') {
            $model = $this->resolveModel(Warehouse::class, $warehouse, 'warehouse');
            $warehouseId = (int) $model->getKey();
            $applied['warehouse'] = $model->getAttribute('name');
        }

        [$mainId, $subId, $categoryName] = $this->resolveCategory($request->get('category'));
        if ($categoryName !== null) {
            $applied['category'] = $categoryName;
        }

        $productId = null;
        if (($product = $request->get('product')) !== null && $product !== '') {
            $model = $this->resolveModel(Product::class, $product, 'product');
            $productId = (int) $model->getKey();
            $applied['product'] = $model->getAttribute('name');
        }

        $compare = match ($period->compare) {
            'same_period_last_year' => InventoryReportFilters::COMPARE_LAST_YEAR,
            'none' => InventoryReportFilters::COMPARE_NONE,
            default => InventoryReportFilters::COMPARE_PREVIOUS,
        };

        return [new InventoryReportFilters($period->from, $period->to, $compare, $warehouseId, $mainId, $subId, $productId), $applied];
    }
}
