<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Filament\Pages\Reports\InventoryMovementsReport;
use App\Filament\Resources\PurchaseInvoices\PurchaseInvoiceResource;
use App\Models\Supplier;
use App\Services\Reports\Inventory\InventoryInsightsReportService;
use App\Services\Reports\Inventory\InventoryReportFilters;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('purchases_suppliers')]
#[Title('Purchases and suppliers')]
#[Description(<<<'TEXT'
Purchases in a period: net purchases per supplier (invoices minus purchase returns) with shares, the purchase invoices themselves (optionally for one supplier), and the products bought most by value.
Use for "how much did we buy from each supplier", "last invoices from supplier X", "what do we spend most on".
Not for stock levels (inventory_status) or waste and losses (inventory_movements). Cost price changes are in inventory_movements view=cost_changes.
Examples: «كم اشترينا من كل مورد هذا الشهر؟», «اعرض آخر فواتير المورد الأمين», "Top purchased items by value last month".
TEXT)]
#[IsReadOnly]
class PurchasesSuppliersTool extends BusinessTool
{
    private readonly InventoryInsightsReportService $insights;

    /** Services come from the container when the tool is built without them. */
    public function __construct(?InventoryInsightsReportService $insights = null)
    {
        $this->insights = $insights ?? app(InventoryInsightsReportService::class);
    }

    protected function reportPages(): array
    {
        return [InventoryMovementsReport::class];
    }

    public function allowed(): bool
    {
        return Auth::user() !== null && (PurchaseInvoiceResource::canViewAny() || parent::allowed());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->periodSchema($schema, compare: false),
            'supplier' => $schema->string()->description('Supplier name or id: lists that supplier\'s invoices.'),
            'limit' => $schema->integer()->description('Rows per list. Default 10, max 100.'),
        ];
    }

    protected function run(Request $request): array
    {
        $period = $this->period($request, 'this_month', 'none');
        $filters = new InventoryReportFilters($period->from, $period->to, InventoryReportFilters::COMPARE_NONE);
        $limit = $this->limit($request);
        [$from, $to] = [$period->from->toDateString(), $period->to->toDateString()];

        $supplier = null;
        if (($value = $request->get('supplier')) !== null && $value !== '') {
            $supplier = $this->resolveModel(Supplier::class, $value, 'supplier');
        }

        $invoices = DB::table('purchase_invoices as pi')
            ->leftJoin('suppliers as s', 's.id', '=', 'pi.supplier_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'pi.warehouse_id')
            ->whereNotNull('pi.closed_at')
            ->whereBetween('pi.business_date', [$from, $to])
            ->when($supplier !== null, fn ($q) => $q->where('pi.supplier_id', $supplier->getKey()))
            ->orderByDesc('pi.business_date')
            ->orderByDesc('pi.id')
            ->limit($limit)
            ->get(['pi.id', 'pi.business_date', 'pi.total', 's.name as supplier', 'w.name as warehouse'])
            ->map(fn ($row) => [
                'invoice_id' => (int) $row->id,
                'date' => substr((string) $row->business_date, 0, 10),
                'supplier' => $row->supplier,
                'warehouse' => $row->warehouse,
                'total' => round((float) $row->total, 2),
            ])->all();

        $topItems = DB::table('purchase_invoice_items as it')
            ->join('purchase_invoices as pi', 'pi.id', '=', 'it.purchase_invoice_id')
            ->join('products as p', 'p.id', '=', 'it.product_id')
            ->whereNotNull('pi.closed_at')
            ->whereBetween('pi.business_date', [$from, $to])
            ->when($supplier !== null, fn ($q) => $q->where('pi.supplier_id', $supplier->getKey()))
            ->groupBy('p.id', 'p.name', 'p.unit')
            ->select('p.name', 'p.unit')
            ->selectRaw('SUM(it.quantity) as qty, SUM(it.total) as value')
            ->orderByDesc('value')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => ['product' => $row->name, 'unit' => $row->unit, 'quantity' => (float) $row->qty, 'value' => round((float) $row->value, 2)])
            ->all();

        $suppliers = $this->insights->suppliers($filters);
        if ($supplier !== null) {
            $suppliers = array_values(array_filter($suppliers, fn (array $row) => $row['supplier'] === $supplier->getAttribute('name')));
        }

        return $this->envelope($period, [
            'suppliers' => array_slice($suppliers, 0, $limit),
            'invoices' => $invoices,
            'top_items' => $topItems,
        ], [
            'net' => 'Closed purchase invoices minus purchase returns, at invoice value.',
            'invoices' => 'Closed purchase invoices by business date, newest first.',
        ], [], $supplier !== null ? ['supplier' => $supplier->getAttribute('name')] : []);
    }
}
