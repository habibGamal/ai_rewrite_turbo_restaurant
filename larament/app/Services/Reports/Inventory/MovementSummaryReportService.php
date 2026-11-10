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
use Illuminate\Support\Fluent;

class MovementSummaryReportService
{
    /**
     * Ordered movement reason definitions for the summary report.
     *
     * @var array<string, array{id: int, key: string, reason: string, operation: string, label: string}>
     */
    public const REASON_DEFINITIONS = [
        'purchase' => [
            'id' => 1,
            'key' => 'purchase',
            'reason' => 'purchase',
            'operation' => 'in',
            'label' => 'مشتريات',
        ],
        'purchase_return' => [
            'id' => 2,
            'key' => 'purchase_return',
            'reason' => 'purchase_return',
            'operation' => 'out',
            'label' => 'مرتجع مشتريات',
        ],
        'order' => [
            'id' => 3,
            'key' => 'order',
            'reason' => 'order',
            'operation' => 'out',
            'label' => 'مبيعات (طلبات)',
        ],
        'order_return' => [
            'id' => 4,
            'key' => 'order_return',
            'reason' => 'order_return',
            'operation' => 'in',
            'label' => 'مرتجع مبيعات (طلبات)',
        ],
        'waste' => [
            'id' => 5,
            'key' => 'waste',
            'reason' => 'waste',
            'operation' => 'out',
            'label' => 'تالف',
        ],
        'stocktaking_in' => [
            'id' => 6,
            'key' => 'stocktaking_in',
            'reason' => 'stocktaking',
            'operation' => 'in',
            'label' => 'جرد (وارد)',
        ],
        'stocktaking_out' => [
            'id' => 7,
            'key' => 'stocktaking_out',
            'reason' => 'stocktaking',
            'operation' => 'out',
            'label' => 'جرد (صادر)',
        ],
        'transfer_in' => [
            'id' => 8,
            'key' => 'transfer_in',
            'reason' => 'transfer_in',
            'operation' => 'in',
            'label' => 'تحويل وارد',
        ],
        'transfer_out' => [
            'id' => 9,
            'key' => 'transfer_out',
            'reason' => 'transfer_out',
            'operation' => 'out',
            'label' => 'تحويل صادر',
        ],
        'manufacturing_in' => [
            'id' => 10,
            'key' => 'manufacturing_in',
            'reason' => 'manufacturing_in',
            'operation' => 'in',
            'label' => 'تصنيع وارد',
        ],
        'manufacturing_out' => [
            'id' => 11,
            'key' => 'manufacturing_out',
            'reason' => 'manufacturing_out',
            'operation' => 'out',
            'label' => 'تصنيع صادر',
        ],
        'stock_issue' => [
            'id' => 12,
            'key' => 'stock_issue',
            'reason' => 'stock_issue',
            'operation' => 'out',
            'label' => 'صرف مخزني',
        ],
        'other_in' => [
            'id' => 13,
            'key' => 'other_in',
            'reason' => 'other',
            'operation' => 'in',
            'label' => 'تسويات أخرى (وارد)',
        ],
        'other_out' => [
            'id' => 14,
            'key' => 'other_out',
            'reason' => 'other',
            'operation' => 'out',
            'label' => 'تسويات أخرى (صادر)',
        ],
    ];

    /**
     * Build movement summary rows keyed by movement reason key, with drill-down documents on each row.
     *
     * @param  array<string, mixed>|string|int|null  $from
     * @return Collection<string, Fluent<string, mixed>>
     */
    public static function rows(
        array|string|int|null $from = null,
        string|int|null $to = null,
        string|int|null $warehouseId = null,
        ?int $mainCategoryId = null,
        ?int $subCategoryId = null,
        ?int $productId = null,
    ): Collection {
        [$resolvedFrom, $resolvedTo, $resolvedWarehouseId, $resolvedMainCatId, $resolvedSubCatId, $resolvedProductId] = self::normalizeArguments(
            $from,
            $to,
            $warehouseId,
            $mainCategoryId,
            $subCategoryId,
            $productId,
        );

        $movements = self::filteredMovementsQuery(
            $resolvedFrom,
            $resolvedTo,
            $resolvedWarehouseId,
            $resolvedMainCatId,
            $resolvedSubCatId,
            $resolvedProductId,
        )->get();

        /** @var array<string, Collection<int, InventoryItemMovement>> $grouped */
        $grouped = [];
        foreach (array_keys(self::REASON_DEFINITIONS) as $key) {
            $grouped[$key] = collect();
        }

        foreach ($movements as $movement) {
            $key = self::reasonKeyForMovement($movement);
            if (isset($grouped[$key])) {
                $grouped[$key]->push($movement);
            }
        }

        $result = collect();

        foreach (self::REASON_DEFINITIONS as $key => $def) {
            $items = $grouped[$key];
            $totalQuantity = round((float) $items->sum(fn (InventoryItemMovement $m): float => (float) $m->quantity), 3);
            if ($totalQuantity == 0.0) {
                $totalQuantity = 0.0;
            }

            $totalValue = round((float) $items->sum(fn (InventoryItemMovement $m): float => (float) $m->total_cost), 2);
            if ($totalValue == 0.0) {
                $totalValue = 0.0;
            }

            $documents = self::buildDocumentsFromMovements($items);

            $result->put($key, new Fluent([
                'id' => $def['id'],
                'key' => $key,
                'reason' => $key,
                'reason_code' => $def['reason'],
                'label' => $def['label'],
                'operation' => $def['operation'],
                'quantity' => $totalQuantity,
                'total_quantity' => $totalQuantity,
                'value' => $totalValue,
                'total_value' => $totalValue,
                'total_cost' => $totalValue,
                'movements_count' => $items->count(),
                'documents_count' => $documents->count(),
                'documents' => $documents,
            ]));
        }

        return $result;
    }

    /**
     * Alias for rows().
     *
     * @param  array<string, mixed>|string|int|null  $from
     * @return Collection<string, Fluent<string, mixed>>
     */
    public static function summarize(
        array|string|int|null $from = null,
        string|int|null $to = null,
        string|int|null $warehouseId = null,
        ?int $mainCategoryId = null,
        ?int $subCategoryId = null,
        ?int $productId = null,
    ): Collection {
        return self::rows($from, $to, $warehouseId, $mainCategoryId, $subCategoryId, $productId);
    }

    /**
     * Drill down to the documents behind a specific movement summary reason key.
     *
     * @return Collection<int, Fluent<string, mixed>>
     */
    public static function documents(
        string $reasonKey,
        ?string $from = null,
        ?string $to = null,
        ?int $warehouseId = null,
        ?int $mainCategoryId = null,
        ?int $subCategoryId = null,
        ?int $productId = null,
    ): Collection {
        $summary = self::rows($from, $to, $warehouseId, $mainCategoryId, $subCategoryId, $productId);
        $row = $summary->get($reasonKey);
        $documents = $row instanceof Fluent ? $row->get('documents') : null;

        if ($documents instanceof Collection) {
            return $documents;
        }

        return collect();
    }

    /**
     * Alias for documents().
     *
     * @return Collection<int, Fluent<string, mixed>>
     */
    public static function drillDown(
        string $reasonKey,
        ?string $from = null,
        ?string $to = null,
        ?int $warehouseId = null,
        ?int $mainCategoryId = null,
        ?int $subCategoryId = null,
        ?int $productId = null,
    ): Collection {
        return self::documents($reasonKey, $from, $to, $warehouseId, $mainCategoryId, $subCategoryId, $productId);
    }

    /**
     * Build an Eloquent query returning the summary rows for the Filament Table and Exporter.
     *
     * @return Builder<InventoryItemMovement>
     */
    public static function query(): Builder
    {
        $selects = [];
        foreach (self::REASON_DEFINITIONS as $def) {
            $id = (int) $def['id'];
            $key = $def['key'];
            $label = $def['label'];
            $operation = $def['operation'];
            $selects[] = "SELECT {$id} AS id, '{$key}' AS reason_key, '{$label}' AS reason_label, '{$operation}' AS operation";
        }

        $unionSql = implode(' UNION ALL ', $selects);

        return InventoryItemMovement::query()
            ->fromSub($unionSql, 'inventory_item_movements')
            ->select([
                'inventory_item_movements.id',
                'inventory_item_movements.reason_key',
                'inventory_item_movements.reason_label',
                'inventory_item_movements.operation',
            ])
            ->orderBy('inventory_item_movements.id', 'asc');
    }

    public static function reasonKeyById(int $id): ?string
    {
        foreach (self::REASON_DEFINITIONS as $key => $def) {
            if ($def['id'] === $id) {
                return $key;
            }
        }

        return null;
    }

    public static function reasonKeyForMovement(InventoryItemMovement $movement): string
    {
        $reasonValue = $movement->reason->value;

        if ($reasonValue === MovementReason::STOCKTAKING->value) {
            return $movement->operation === InventoryMovementOperation::IN
                ? 'stocktaking_in'
                : 'stocktaking_out';
        }

        if (isset(self::REASON_DEFINITIONS[$reasonValue])) {
            return $reasonValue;
        }

        // manual, adjustment, initial_stock and the legacy transfer reason still move stock,
        // so they are summarised here to keep the report in agreement with the ledger.
        return $movement->operation === InventoryMovementOperation::IN ? 'other_in' : 'other_out';
    }

    /**
     * @return Builder<InventoryItemMovement>
     */
    public static function filteredMovementsQuery(
        ?string $from = null,
        ?string $to = null,
        ?int $warehouseId = null,
        ?int $mainCategoryId = null,
        ?int $subCategoryId = null,
        ?int $productId = null,
    ): Builder {
        $fromDate = $from ? Carbon::parse($from)->toDateString() : Carbon::today()->subDays(29)->toDateString();
        $toDate = $to ? Carbon::parse($to)->toDateString().' 23:59:59' : Carbon::today()->toDateString().' 23:59:59';

        return InventoryItemMovement::query()
            ->with(['user', 'warehouse', 'product.category'])
            ->whereBetween('inventory_item_movements.business_date', [$fromDate, $toDate])
            ->when($warehouseId !== null, fn (Builder $q) => $q->where('inventory_item_movements.warehouse_id', $warehouseId))
            ->when($productId !== null, fn (Builder $q) => $q->where('inventory_item_movements.product_id', $productId))
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
            ->orderBy('inventory_item_movements.created_at', 'asc')
            ->orderBy('inventory_item_movements.id', 'asc');
    }

    /**
     * Group a collection of movements into distinct drill-down document summaries.
     *
     * @param  Collection<int, InventoryItemMovement>  $movements
     * @return Collection<int, Fluent<string, mixed>>
     */
    private static function buildDocumentsFromMovements(Collection $movements): Collection
    {
        if ($movements->isEmpty()) {
            return collect();
        }

        $grouped = $movements->groupBy(function (InventoryItemMovement $m): string {
            if ($m->referenceable_type !== null && $m->referenceable_id !== null) {
                return $m->referenceable_type.':'.$m->referenceable_id;
            }

            return 'movement:'.$m->id;
        });

        $docs = collect();

        foreach ($grouped as $docMovements) {
            /** @var InventoryItemMovement $first */
            $first = $docMovements->first();
            $refType = $first->referenceable_type;
            $refId = $first->referenceable_id;

            $docTypeLabel = ItemLedgerReportService::documentTypeLabel($refType, $first->reason);
            $docNumber = $refId !== null ? (string) $refId : (string) $first->id;
            $docUrl = ItemLedgerReportService::documentUrl($refType, $refId);

            $qty = round((float) $docMovements->sum(fn (InventoryItemMovement $m): float => (float) $m->quantity), 3);
            if ($qty == 0.0) {
                $qty = 0.0;
            }

            $val = round((float) $docMovements->sum(fn (InventoryItemMovement $m): float => (float) $m->total_cost), 2);
            if ($val == 0.0) {
                $val = 0.0;
            }

            $userName = $first->user instanceof User ? $first->user->name : null;
            $warehouseName = $first->warehouse instanceof Warehouse ? $first->warehouse->name : null;
            $date = $first->created_at ? $first->created_at->toDateString() : '';

            $docs->push(new Fluent([
                'referenceable_type' => $refType,
                'referenceable_id' => $refId,
                'document_type' => $docTypeLabel,
                'document_type_label' => $docTypeLabel,
                'document_number' => $docNumber,
                'document' => "{$docTypeLabel} #{$docNumber}",
                'document_url' => $docUrl,
                'url' => $docUrl,
                'link' => $docUrl,
                'date' => $date,
                'created_at' => $first->created_at,
                'quantity' => $qty,
                'total_quantity' => $qty,
                'value' => $val,
                'total_value' => $val,
                'total_cost' => $val,
                'user_id' => $first->user_id,
                'user' => $userName,
                'user_name' => $userName,
                'warehouse_id' => $first->warehouse_id,
                'warehouse' => $warehouseName,
                'warehouse_name' => $warehouseName,
            ]));
        }

        return $docs->values();
    }

    /**
     * @param  array<string, mixed>|string|int|null  $from
     * @return array{0: ?string, 1: ?string, 2: ?int, 3: ?int, 4: ?int, 5: ?int}
     */
    private static function normalizeArguments(
        array|string|int|null $from,
        string|int|null $to,
        string|int|null $warehouseId,
        ?int $mainCategoryId,
        ?int $subCategoryId,
        ?int $productId,
    ): array {
        if (is_array($from)) {
            $f = $from['from'] ?? $from['startDate'] ?? $from['start'] ?? null;
            $t = $from['to'] ?? $from['endDate'] ?? $from['end'] ?? null;
            $w = ItemLedgerReportService::nullableId($from['warehouse_id'] ?? $from['warehouseId'] ?? null);
            $mc = ItemLedgerReportService::nullableId($from['main_category_id'] ?? $from['mainCategoryId'] ?? null);
            $sc = ItemLedgerReportService::nullableId($from['sub_category_id'] ?? $from['subCategoryId'] ?? null);
            $p = ItemLedgerReportService::nullableId($from['product_id'] ?? $from['productId'] ?? null);

            return [
                is_string($f) ? $f : null,
                is_string($t) ? $t : null,
                $w,
                $mc,
                $sc,
                $p,
            ];
        }

        if ((is_int($from) || $from === null) && is_string($to) && preg_match('/^\d{4}-\d{2}/', $to)) {
            return [
                $to,
                is_string($warehouseId) ? $warehouseId : null,
                is_int($from) ? $from : null,
                $mainCategoryId,
                $subCategoryId,
                $productId,
            ];
        }

        return [
            is_string($from) ? $from : null,
            is_string($to) ? $to : null,
            ItemLedgerReportService::nullableId($warehouseId),
            $mainCategoryId,
            $subCategoryId,
            $productId,
        ];
    }
}
