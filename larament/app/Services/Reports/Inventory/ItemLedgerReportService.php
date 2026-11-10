<?php

namespace App\Services\Reports\Inventory;

use App\Enums\InventoryMovementOperation;
use App\Enums\MovementReason;
use App\Models\Category;
use App\Models\InventoryItemMovement;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Fluent;

class ItemLedgerReportService
{
    /**
     * Map of referenceable morph class strings to Arabic document labels.
     * Keyed by class-name strings (no direct import of StockTransfer).
     *
     * @var array<string, string>
     */
    public const DOCUMENT_TYPE_LABELS = [
        'App\\Models\\PurchaseInvoice' => 'فاتورة شراء',
        'App\\Models\\ReturnPurchaseInvoice' => 'مرتجع شراء',
        'App\\Models\\Waste' => 'سجل تالف',
        'App\\Models\\Stocktaking' => 'جرد',
        'App\\Models\\Order' => 'طلب',
        'App\\Models\\OrderReturn' => 'مرتجع طلب',
        'App\\Models\\ManufacturingOrder' => 'أمر تصنيع',
        'App\\Models\\StockIssue' => 'إذن صرف',
        'App\\Models\\StockTransfer' => 'إذن تحويل',
    ];

    /**
     * Map of referenceable morph class strings to Filament view route names.
     *
     * @var array<string, string>
     */
    public const DOCUMENT_TYPE_ROUTES = [
        'App\\Models\\PurchaseInvoice' => 'filament.admin.resources.purchase-invoices.view',
        'App\\Models\\ReturnPurchaseInvoice' => 'filament.admin.resources.return-purchase-invoices.view',
        'App\\Models\\Waste' => 'filament.admin.resources.wastes.view',
        'App\\Models\\Stocktaking' => 'filament.admin.resources.stocktakings.view',
        'App\\Models\\Order' => 'filament.admin.resources.orders.view',
        'App\\Models\\OrderReturn' => 'filament.admin.resources.order-returns.view',
        'App\\Models\\ManufacturingOrder' => 'filament.admin.resources.manufacturing-orders.view',
        'App\\Models\\StockIssue' => 'filament.admin.resources.stock-issues.view',
        'App\\Models\\StockTransfer' => 'filament.admin.resources.stock-transfers.view',
    ];

    public const GENERIC_DOCUMENT_LABEL = 'مستند مخزني';

    public static function documentTypeLabel(?string $referenceableType, MovementReason|string|null $reason = null): string
    {
        if ($referenceableType !== null && $referenceableType !== '') {
            return self::DOCUMENT_TYPE_LABELS[$referenceableType] ?? self::GENERIC_DOCUMENT_LABEL;
        }

        if ($reason instanceof MovementReason) {
            return $reason->label();
        }

        if (is_string($reason) && $reason !== '') {
            $enum = MovementReason::tryFrom($reason);
            if ($enum !== null) {
                return $enum->label();
            }
        }

        return self::GENERIC_DOCUMENT_LABEL;
    }

    public static function documentUrl(?string $referenceableType, int|string|null $referenceableId): ?string
    {
        if ($referenceableType === null || $referenceableType === '' || $referenceableId === null || $referenceableId === '') {
            return null;
        }

        $routeName = self::DOCUMENT_TYPE_ROUTES[$referenceableType] ?? null;

        if ($routeName === null || ! Route::has($routeName)) {
            return null;
        }

        return route($routeName, ['record' => $referenceableId]);
    }

    public static function openingBalance(int $productId, ?int $warehouseId, string $from): float
    {
        $fromBound = Carbon::parse($from)->startOfDay();

        $sum = InventoryItemMovement::query()
            ->where('product_id', $productId)
            ->when($warehouseId !== null, fn (Builder $query) => $query->where('warehouse_id', $warehouseId))
            ->where('created_at', '<', $fromBound)
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN operation = ? THEN quantity ELSE -quantity END), 0) as balance',
                [InventoryMovementOperation::IN->value]
            )
            ->value('balance');

        $balance = round((float) $sum, 3);

        return $balance == 0.0 ? 0.0 : $balance;
    }

    /**
     * Build item ledger rows in date order (created_at, id) with running balance.
     *
     * @return Collection<int, Fluent<string, mixed>>
     */
    public static function rows(int $productId, ?int $warehouseId, string $from, string $to): Collection
    {
        return self::filteredRows($productId, $warehouseId, $from, $to);
    }

    /**
     * Build item ledger rows supporting optional product and category filters.
     *
     * @return Collection<int, Fluent<string, mixed>>
     */
    public static function filteredRows(
        ?int $productId,
        ?int $warehouseId,
        string $from,
        string $to,
        ?int $mainCategoryId = null,
        ?int $subCategoryId = null,
    ): Collection {
        $movements = self::query($productId, $warehouseId, $from, $to, $mainCategoryId, $subCategoryId)
            ->get();

        if ($movements->isEmpty()) {
            return collect();
        }

        /** @var array<int, float> $openingByProduct */
        $openingByProduct = [];
        /** @var array<int, float> $runningByProduct */
        $runningByProduct = [];

        foreach ($movements->pluck('product_id')->unique() as $pid) {
            $productIdInt = (int) $pid;
            $opening = self::openingBalance($productIdInt, $warehouseId, $from);
            $openingByProduct[$productIdInt] = $opening;
            $runningByProduct[$productIdInt] = $opening;
        }

        $result = collect();

        foreach ($movements as $movement) {
            $pid = (int) $movement->product_id;
            $opening = $openingByProduct[$pid] ?? 0.0;
            $running = $runningByProduct[$pid] ?? 0.0;

            $isIn = $movement->operation === InventoryMovementOperation::IN;
            $qty = round((float) $movement->quantity, 3);
            $inQty = $isIn ? $qty : 0.0;
            $outQty = $isIn ? 0.0 : $qty;

            $running = round($running + ($isIn ? $qty : -$qty), 3);
            if ($running == 0.0) {
                $running = 0.0;
            }
            $runningByProduct[$pid] = $running;

            $unitCost = round((float) $movement->unit_cost, 4);
            $value = round((float) $movement->total_cost, 2);
            $docTypeLabel = self::documentTypeLabel($movement->referenceable_type, $movement->reason);
            $docNumber = $movement->referenceable_id !== null ? (string) $movement->referenceable_id : null;
            $docUrl = self::documentUrl($movement->referenceable_type, $movement->referenceable_id);
            $userName = $movement->user instanceof User ? $movement->user->name : null;
            $warehouseName = $movement->warehouse instanceof Warehouse ? $movement->warehouse->name : null;
            $productName = $movement->product->name;
            $date = $movement->created_at ? $movement->created_at->toDateString() : '';

            $result->push(new Fluent([
                'id' => (int) $movement->id,
                'movement_id' => (int) $movement->id,
                'product_id' => $pid,
                'product_name' => $productName,
                'warehouse_id' => (int) $movement->warehouse_id,
                'warehouse' => $warehouseName,
                'warehouse_name' => $warehouseName,
                'date' => $date,
                'created_at' => $movement->created_at,
                'operation' => $movement->operation->value,
                'reason' => $movement->reason->value,
                'reason_label' => $movement->reason->label(),
                'referenceable_type' => $movement->referenceable_type,
                'referenceable_id' => $movement->referenceable_id,
                'document_type' => $docTypeLabel,
                'document_type_label' => $docTypeLabel,
                'document' => $docNumber !== null ? "{$docTypeLabel} #{$docNumber}" : $docTypeLabel,
                'document_number' => $docNumber,
                'document_url' => $docUrl,
                'url' => $docUrl,
                'link' => $docUrl,
                'in' => $inQty,
                'in_qty' => $inQty,
                'in_quantity' => $inQty,
                'out' => $outQty,
                'out_qty' => $outQty,
                'out_quantity' => $outQty,
                'quantity' => $qty,
                'opening_balance' => $opening,
                'running_balance' => $running,
                'balance' => $running,
                'unit_cost' => $unitCost,
                'value' => $value,
                'total_cost' => $value,
                'user_id' => $movement->user_id,
                'user' => $userName,
                'user_name' => $userName,
            ]));
        }

        return $result;
    }

    /**
     * Build the Eloquent query for the Item Ledger table and exporter.
     *
     * @return Builder<InventoryItemMovement>
     */
    public static function query(
        ?int $productId,
        ?int $warehouseId,
        string $from,
        string $to,
        ?int $mainCategoryId = null,
        ?int $subCategoryId = null,
    ): Builder {
        $fromBound = Carbon::parse($from)->startOfDay();
        $toBound = Carbon::parse($to)->endOfDay();

        return InventoryItemMovement::query()
            ->with(['user', 'warehouse', 'product.category'])
            ->when($productId !== null, fn (Builder $q) => $q->where('inventory_item_movements.product_id', $productId))
            ->when($warehouseId !== null, fn (Builder $q) => $q->where('inventory_item_movements.warehouse_id', $warehouseId))
            ->when($subCategoryId !== null, function (Builder $q) use ($subCategoryId) {
                $q->whereHas('product', fn (Builder $pq) => $pq->where('category_id', $subCategoryId));
            })
            ->when($mainCategoryId !== null && $subCategoryId === null, function (Builder $q) use ($mainCategoryId) {
                $q->whereHas('product', function (Builder $pq) use ($mainCategoryId) {
                    $pq->whereIn(
                        'category_id',
                        Category::query()
                            ->select('id')
                            ->where('id', $mainCategoryId)
                            ->orWhere('parent_id', $mainCategoryId)
                    );
                });
            })
            ->whereBetween('inventory_item_movements.created_at', [$fromBound, $toBound])
            ->orderBy('inventory_item_movements.created_at', 'asc')
            ->orderBy('inventory_item_movements.id', 'asc');
    }

    /**
     * Compute running balance for a specific movement record within a warehouse scope.
     */
    public static function runningBalanceForMovement(InventoryItemMovement $movement, ?int $warehouseId = null): float
    {
        $createdAt = $movement->created_at;

        $sum = InventoryItemMovement::query()
            ->where('product_id', $movement->product_id)
            ->when($warehouseId !== null, fn (Builder $q) => $q->where('warehouse_id', $warehouseId))
            ->where(function (Builder $q) use ($createdAt, $movement) {
                if ($createdAt !== null) {
                    $q->where('created_at', '<', $createdAt)
                        ->orWhere(function (Builder $sameTime) use ($createdAt, $movement) {
                            $sameTime->where('created_at', '=', $createdAt)
                                ->where('id', '<=', $movement->id);
                        });
                } else {
                    $q->where('id', '<=', $movement->id);
                }
            })
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN operation = ? THEN quantity ELSE -quantity END), 0) as balance',
                [InventoryMovementOperation::IN->value]
            )
            ->value('balance');

        $balance = round((float) $sum, 3);

        return $balance == 0.0 ? 0.0 : $balance;
    }

    public static function nullableId(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
