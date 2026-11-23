<?php

namespace App\Services\ImportStrategies;

use App\Enums\InventoryMovementOperation;
use App\Enums\MovementReason;
use App\Enums\ProductType;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\InventoryItemMovement;
use App\Models\Product;
use App\Models\Warehouse;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class ConsumableWarehouseImportStrategy extends AbstractImportStrategy
{
    /**
     * Columns that represent the product barcode.
     */
    protected array $barcodeAliases = [
        'barcode', 'bar_code', 'الباركود', 'باركود', 'الباركود ',
    ];

    /**
     * Columns that represent the product name.
     */
    protected array $nameAliases = [
        'name', 'product_name', 'item_name', 'الصنف', 'أسم المنتج', 'اسم المنتج', 'اسم الصنف',
    ];

    /**
     * Columns that represent the product code / reference.
     */
    protected array $codeAliases = [
        'code', 'item_code', 'product_code', 'sku', 'product_ref', 'كود الصنف', 'كود', 'الرمز',
    ];

    /**
     * Columns that represent the product category.
     */
    protected array $categoryAliases = [
        'category', 'category_name', 'الفئة', 'القسم', 'التصنيف', 'تصنيف', 'فئة', 'قسم',
    ];

    /**
     * Columns that represent the selling price.
     */
    protected array $priceAliases = [
        'price', 'sale_price', 'selling_price', 'سعر البيع', 'سعر بيع', 'السعر', 'سعر',
    ];

    /**
     * Columns that represent the cost price.
     */
    protected array $costAliases = [
        'cost', 'cost_price', 'purchase_price', 'سعر التكلفة', 'سعر تكلفة', 'التكلفة', 'تكلفة',
    ];

    /**
     * Columns that represent the total quantity to be omitted.
     */
    protected array $totalAliases = [
        'total', 'sum', 'الإجمالي', 'الاجمالي', 'الإجمالى', 'المجموع', 'المجموع الكلي',
    ];

    public function getKey(): string
    {
        return 'consumable_warehouse';
    }

    public function getLabel(): string
    {
        return 'منتجات استهلاكية وأرصدة المخزن (Consumables with Warehouse Quantities)';
    }

    public function getDescription(): string
    {
        return 'استيراد منتجات كأصناف استهلاكية وتحديث التصنيف والأسعار والتكلفة والباركود وأرصدة المخازن مع استبعاد عمود الإجمالي.';
    }

    /**
     * Check if this strategy supports the file.
     */
    public function supports(UploadedFile|string $file): bool
    {
        try {
            $filePath = $this->resolveFilePath($file);

            if ($this->isJsonFile($filePath)) {
                $data = $this->readJson($filePath);
                if (! is_array($data)) {
                    return false;
                }

                if (isset($data['import_type']) && $data['import_type'] === $this->getKey()) {
                    return true;
                }

                // Check list of items
                $rows = isset($data['consumables']) ? $data['consumables'] : (array_is_list($data) ? $data : null);
                if (is_array($rows) && ! empty($rows)) {
                    $first = reset($rows);
                    if (is_array($first)) {
                        $keys = array_keys($first);
                        $hasBarcode = ! empty(array_intersect($keys, $this->barcodeAliases));
                        $hasName = ! empty(array_intersect($keys, $this->nameAliases));
                        $hasCode = ! empty(array_intersect($keys, $this->codeAliases));

                        return ($hasBarcode || $hasCode) && $hasName;
                    }
                }

                return false;
            }

            // Spreadsheet inspection
            $spreadsheet = $this->loadSpreadsheet($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $data = $this->extractWorksheetData($sheet);
            $headers = $data['headers'];

            $hasBarcode = ! empty(array_intersect($headers, $this->barcodeAliases));
            $hasName = ! empty(array_intersect($headers, $this->nameAliases));
            $hasCode = ! empty(array_intersect($headers, $this->codeAliases));

            return ($hasBarcode || $hasCode) && $hasName;

        } catch (Exception) {
            return false;
        }
    }

    /**
     * Parse and normalize raw file data into standard consumable item array.
     *
     * @return array{
     *     warehouse_names: array<int, string>,
     *     items: array<int, array{
     *         code: ?string,
     *         name: string,
     *         barcode: ?string,
     *         category: ?string,
     *         price: ?float,
     *         cost: ?float,
     *         unit: ?string,
     *         warehouses: array<string, float>
     *     }>
     * }
     */
    public function parseFileRows(string $filePath): array
    {
        if ($this->isJsonFile($filePath)) {
            $jsonData = $this->readJson($filePath);

            return $this->parseJsonRows($jsonData);
        }

        $spreadsheet = $this->loadSpreadsheet($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $extracted = $this->extractWorksheetData($worksheet);

        return $this->parseTableRows($extracted['headers'], $extracted['rows']);
    }

    /**
     * Parse rows from decoded JSON.
     */
    protected function parseJsonRows(mixed $data): array
    {
        if (! is_array($data)) {
            throw new Exception('محتوى ملف JSON غير صالح (يجب أن يكون مصفوفة سجلات أو كائن يحتوي على مصفوفة consumables)');
        }

        $topWarehouse = $data['warehouse'] ?? null;
        $rawRows = $data['consumables'] ?? ($data['products'] ?? ($data['الاستهلاكي'] ?? null));

        if (! is_array($rawRows) && array_is_list($data)) {
            $rawRows = $data;
        }

        if (! is_array($rawRows)) {
            throw new Exception('لم يتم العثور على مصفوفة المنتجات داخل ملف JSON');
        }

        $items = [];
        $detectedWarehouses = [];

        foreach ($rawRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $parsed = $this->normalizeRow($row, $topWarehouse);
            if ($parsed !== null) {
                $items[] = $parsed;
                foreach (array_keys($parsed['warehouses']) as $wName) {
                    if (! in_array($wName, $detectedWarehouses, true)) {
                        $detectedWarehouses[] = $wName;
                    }
                }
            }
        }

        return [
            'warehouse_names' => $detectedWarehouses,
            'items' => $items,
        ];
    }

    /**
     * Parse rows from tabular format (spreadsheet or table-like array).
     */
    protected function parseTableRows(array $headers, array $rows): array
    {
        $items = [];
        $detectedWarehouses = [];

        // Identify warehouse columns (columns that are not code, name, barcode, category, prices, or total)
        $warehouseCols = [];
        foreach ($headers as $col) {
            $cleanCol = trim($col);
            if ($cleanCol === '') {
                continue;
            }

            if (in_array($cleanCol, $this->codeAliases, true) ||
                in_array($cleanCol, $this->nameAliases, true) ||
                in_array($cleanCol, $this->barcodeAliases, true) ||
                in_array($cleanCol, $this->categoryAliases, true) ||
                in_array($cleanCol, $this->priceAliases, true) ||
                in_array($cleanCol, $this->costAliases, true) ||
                in_array($cleanCol, $this->totalAliases, true) ||
                in_array($cleanCol, ['unit', 'الوحدة'], true)) {
                continue;
            }

            $warehouseCols[] = $cleanCol;
            if (! in_array($cleanCol, $detectedWarehouses, true)) {
                $detectedWarehouses[] = $cleanCol;
            }
        }

        foreach ($rows as $row) {
            $parsed = $this->normalizeRow($row, null, $warehouseCols);
            if ($parsed !== null) {
                $items[] = $parsed;
            }
        }

        return [
            'warehouse_names' => $detectedWarehouses,
            'items' => $items,
        ];
    }

    /**
     * Normalize a single row.
     */
    protected function normalizeRow(array $row, ?string $defaultWarehouse = null, array $warehouseCols = []): ?array
    {
        // Extract name
        $name = null;
        foreach ($this->nameAliases as $alias) {
            if (isset($row[$alias]) && trim((string) $row[$alias]) !== '') {
                $name = trim((string) $row[$alias]);
                break;
            }
        }

        // Extract code
        $code = null;
        foreach ($this->codeAliases as $alias) {
            if (isset($row[$alias]) && trim((string) $row[$alias]) !== '') {
                $code = trim((string) $row[$alias]);
                break;
            }
        }

        // Check if this is a summary row (e.g. الإجمالي الكلي)
        $isSummary = false;
        $combinedCheck = ($name ?? '').' '.($code ?? '');
        if (str_contains($combinedCheck, 'الإجمالي الكلي') ||
            str_contains($combinedCheck, 'الاجمالي الكلي') ||
            str_contains($combinedCheck, 'المجموع الكلي')) {
            $isSummary = true;
        }

        if ($isSummary || empty($name)) {
            return null;
        }

        // Extract barcode
        $barcode = null;
        foreach ($this->barcodeAliases as $alias) {
            if (isset($row[$alias]) && trim((string) $row[$alias]) !== '') {
                $barcode = trim((string) $row[$alias]);
                break;
            }
        }

        // Extract category
        $category = null;
        foreach ($this->categoryAliases as $alias) {
            if (isset($row[$alias]) && trim((string) $row[$alias]) !== '') {
                $category = trim((string) $row[$alias]);
                break;
            }
        }

        // Extract selling price
        $price = null;
        foreach ($this->priceAliases as $alias) {
            if (isset($row[$alias]) && is_numeric($row[$alias])) {
                $price = (float) $row[$alias];
                break;
            }
        }

        // Extract cost price
        $cost = null;
        foreach ($this->costAliases as $alias) {
            if (isset($row[$alias]) && is_numeric($row[$alias])) {
                $cost = (float) $row[$alias];
                break;
            }
        }

        // Extract unit
        $unit = null;
        if (isset($row['unit']) && is_string($row['unit'])) {
            $unit = trim($row['unit']);
        } elseif (isset($row['الوحدة']) && is_string($row['الوحدة'])) {
            $unit = trim($row['الوحدة']);
        }

        // Extract warehouses & quantities
        $warehouses = [];

        // Check for explicit warehouse & quantity properties
        if (isset($row['warehouse']) && isset($row['quantity']) && is_numeric($row['quantity'])) {
            $w = trim((string) $row['warehouse']);
            if ($w !== '') {
                $warehouses[$w] = (float) $row['quantity'];
            }
        }

        // Check columns matching warehouse names
        foreach ($row as $key => $val) {
            $cleanKey = trim((string) $key);

            // Skip total column completely (per user requirement)
            if (in_array($cleanKey, $this->totalAliases, true)) {
                continue;
            }

            // Skip known identity and metadata columns
            if (in_array($cleanKey, $this->nameAliases, true) ||
                in_array($cleanKey, $this->codeAliases, true) ||
                in_array($cleanKey, $this->barcodeAliases, true) ||
                in_array($cleanKey, $this->categoryAliases, true) ||
                in_array($cleanKey, $this->priceAliases, true) ||
                in_array($cleanKey, $this->costAliases, true) ||
                in_array($cleanKey, ['warehouse', 'quantity', 'id', 'type', 'price', 'cost', 'unit', 'الوحدة'], true)) {
                continue;
            }

            // If key is a recognized warehouse column or non-empty string and value is numeric
            if (is_numeric($val) && $cleanKey !== '') {
                $warehouses[$cleanKey] = (float) $val;
            }
        }

        // If no warehouse quantity detected but a quantity was explicitly provided
        if (empty($warehouses) && isset($row['quantity']) && is_numeric($row['quantity'])) {
            $targetWarehouse = $defaultWarehouse ?: 'المخزن الرئيسي';
            $warehouses[$targetWarehouse] = (float) $row['quantity'];
        }

        // Only default to 0 if warehouse columns were explicitly present in the source table
        if (empty($warehouses) && ! empty($warehouseCols)) {
            foreach ($warehouseCols as $wCol) {
                $warehouses[$wCol] = 0.0;
            }
        }

        return [
            'code' => $code,
            'name' => $name,
            'barcode' => $barcode,
            'category' => $category,
            'price' => $price,
            'cost' => $cost,
            'unit' => $unit,
            'warehouses' => $warehouses,
        ];
    }

    /**
     * Analyze structure and return preview metadata.
     */
    public function analyzeStructure(UploadedFile|string $file): array
    {
        try {
            $filePath = $this->resolveFilePath($file);
            $parsed = $this->parseFileRows($filePath);
            $items = $parsed['items'];
            $warehouseNames = $parsed['warehouse_names'];

            // Build headers without total column
            $headers = ['كود الصنف', 'الصنف', 'الباركود'];

            $hasCategory = false;
            $hasPrice = false;
            $hasCost = false;
            foreach ($items as $item) {
                if (! empty($item['category'])) {
                    $hasCategory = true;
                }
                if ($item['price'] !== null) {
                    $hasPrice = true;
                }
                if ($item['cost'] !== null) {
                    $hasCost = true;
                }
                if ($hasCategory && $hasPrice && $hasCost) {
                    break;
                }
            }

            if ($hasCategory) {
                $headers[] = 'التصنيف';
            }
            if ($hasPrice) {
                $headers[] = 'سعر البيع';
            }
            if ($hasCost) {
                $headers[] = 'سعر التكلفة';
            }

            foreach ($warehouseNames as $wName) {
                $headers[] = $wName;
            }

            $sampleData = [];
            foreach (array_slice($items, 0, 5) as $item) {
                $row = [
                    $item['code'] ?? '',
                    $item['name'],
                    $item['barcode'] ?? '',
                ];
                if ($hasCategory) {
                    $row[] = $item['category'] ?? '';
                }
                if ($hasPrice) {
                    $row[] = $item['price'] ?? 0;
                }
                if ($hasCost) {
                    $row[] = $item['cost'] ?? 0;
                }
                foreach ($warehouseNames as $wName) {
                    $row[] = $item['warehouses'][$wName] ?? 0;
                }
                $sampleData[] = $row;
            }

            $sheetKey = 'المنتجات الاستهلاكية والمخازن';
            $totalRows = count($items);

            return [
                'success' => true,
                'total_sheets' => 1,
                'sheets' => [
                    $sheetKey => [
                        'headers' => $headers,
                        'sample_data' => $sampleData,
                        'total_rows' => $totalRows > 0 ? $totalRows + 1 : 0,
                        'total_columns' => count($headers),
                        'estimated_data_rows' => $totalRows,
                    ],
                ],
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'total_sheets' => 0,
                'sheets' => [],
            ];
        }
    }

    /**
     * Import the consumable products and reflect quantities to warehouses.
     */
    public function import(UploadedFile|string $file): array
    {
        $filePath = $this->resolveFilePath($file);

        try {
            $parsed = $this->parseFileRows($filePath);
            $items = $parsed['items'];

            $successCount = 0;
            $errorCount = 0;
            $errors = [];

            // Cache warehouse and category models by name
            $warehouseCache = [];
            $categoryCache = [];

            DB::transaction(function () use ($items, &$warehouseCache, &$categoryCache, &$successCount, &$errorCount, &$errors) {
                foreach ($items as $index => $item) {
                    $rowNum = $index + 1;
                    try {
                        $this->importSingleItem($item, $warehouseCache, $categoryCache);
                        $successCount++;
                    } catch (Exception $e) {
                        $errorCount++;
                        $errors[] = "السطر {$rowNum} ({$item['name']}): ".$e->getMessage();
                    }
                }
            });

            return [
                'success' => true,
                'imported_count' => $successCount,
                'error_count' => $errorCount,
                'errors' => $errors,
                'sheet_results' => [
                    'المنتجات الاستهلاكية والمخازن' => [
                        'success_count' => $successCount,
                        'error_count' => $errorCount,
                        'errors' => array_slice($errors, 0, 10),
                    ],
                ],
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'imported_count' => 0,
                'error_count' => 1,
                'errors' => [$e->getMessage()],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Import or update a single consumable product and its warehouse inventories.
     *
     * @param  array{code: ?string, name: string, barcode: ?string, category?: ?string, price?: ?float, cost?: ?float, unit?: ?string, warehouses: array<string, float>}  $item
     * @param  array<string, Warehouse>  $warehouseCache
     * @param  array<string, Category>  $categoryCache
     */
    protected function importSingleItem(array $item, array &$warehouseCache, array &$categoryCache): void
    {
        $name = trim($item['name']);
        $barcode = ! empty($item['barcode']) ? trim((string) $item['barcode']) : null;
        $code = ! empty($item['code']) ? trim((string) $item['code']) : null;
        $warehouses = $item['warehouses'];

        // 1. Find existing product by barcode first, then by code
        $product = null;
        if ($barcode !== null) {
            $product = Product::where('barcode', $barcode)->first();
        }

        if (! $product && $code !== null) {
            $product = Product::where('product_ref', $code)->orWhere('sku', $code)->first();
        }

        // Only fall back to matching by name if NEITHER barcode nor code was provided
        if (! $product && $barcode === null && $code === null) {
            $product = Product::where('name', $name)->first();
        }

        if (! $product) {
            $product = new Product;
        }

        // Determine primary warehouse
        if (! empty($warehouses)) {
            $primaryWarehouseName = array_key_first($warehouses);
            $primaryWarehouse = $this->getOrCreateWarehouse((string) $primaryWarehouseName, $warehouseCache);
            $product->warehouse_id = $primaryWarehouse->id;
        } elseif (! $product->exists || empty($product->warehouse_id)) {
            $primaryWarehouse = $this->getOrCreateWarehouse('المخزن الرئيسي', $warehouseCache);
            $product->warehouse_id = $primaryWarehouse->id;
        }

        // 2. Set product attributes
        $product->name = $name;
        $product->type = ProductType::Consumable;
        $product->is_active = true;

        // Category mapping
        if (! empty($item['category'])) {
            $category = $this->getOrCreateCategory((string) $item['category'], $categoryCache);
            $product->category_id = $category->id;
        } elseif (! $product->exists || empty($product->category_id)) {
            $category = $this->getOrCreateCategory('مستهلكات', $categoryCache);
            $product->category_id = $category->id;
        }

        // Price mapping
        if (isset($item['price'])) {
            $product->price = (float) $item['price'];
        } elseif (! $product->exists) {
            $product->price = 0;
        }

        // Cost mapping
        if (isset($item['cost'])) {
            $product->cost = (float) $item['cost'];
        } elseif (! $product->exists) {
            $product->cost = 0;
        }

        // Unit
        if (! empty($item['unit'])) {
            $product->unit = (string) $item['unit'];
        } elseif (! $product->exists || empty($product->unit)) {
            $product->unit = 'piece';
        }

        // Barcode
        if ($barcode !== null) {
            $product->barcode = $barcode;
        }

        // Avoid unique constraint conflicts for product_ref and sku
        if ($code !== null) {
            $refOwner = Product::where('product_ref', $code)->where('id', '!=', $product->id ?? 0)->exists();
            if (! $refOwner) {
                $product->product_ref = $code;
            }

            $skuOwner = Product::where('sku', $code)->where('id', '!=', $product->id ?? 0)->exists();
            if (! $skuOwner) {
                $product->sku = $code;
            }
        }

        $product->save();

        // 3. Reflect warehouse quantities if warehouse data is provided
        if (! empty($warehouses)) {
            foreach ($warehouses as $wName => $quantity) {
                $warehouse = $this->getOrCreateWarehouse((string) $wName, $warehouseCache);
                $qty = (float) $quantity;

                $inventoryItem = InventoryItem::firstOrNew([
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouse->id,
                ]);

                $inventoryItem->quantity = $qty;
                $inventoryItem->save();

                // Record initial stock movement for stock tracking and audit
                if ($qty > 0) {
                    InventoryItemMovement::create([
                        'product_id' => $product->id,
                        'warehouse_id' => $warehouse->id,
                        'operation' => InventoryMovementOperation::IN,
                        'quantity' => $qty,
                        'reason' => MovementReason::INITIAL_STOCK,
                        'notes' => 'استيراد رصيد مخزني من ملف المنتجات الاستهلاكية',
                        'business_date' => now()->toDateString(),
                    ]);
                }
            }
        }
    }

    /**
     * Get or create a Warehouse instance by name.
     *
     * @param  array<string, Warehouse>  $warehouseCache
     */
    protected function getOrCreateWarehouse(string $name, array &$warehouseCache): Warehouse
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            $trimmed = 'المخزن الرئيسي';
        }

        if (isset($warehouseCache[$trimmed])) {
            return $warehouseCache[$trimmed];
        }

        $warehouse = Warehouse::firstOrCreate(
            ['name' => $trimmed],
            [
                'name' => $trimmed,
                'is_active' => true,
                'is_default' => ($trimmed === 'المخزن الرئيسي'),
            ]
        );

        $warehouseCache[$trimmed] = $warehouse;

        return $warehouse;
    }

    /**
     * Get or create a Category instance by name.
     *
     * @param  array<string, Category>  $categoryCache
     */
    protected function getOrCreateCategory(string $name, array &$categoryCache): Category
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            $trimmed = 'مستهلكات';
        }

        if (isset($categoryCache[$trimmed])) {
            return $categoryCache[$trimmed];
        }

        $category = Category::firstOrCreate(['name' => $trimmed], ['name' => $trimmed]);
        $categoryCache[$trimmed] = $category;

        return $category;
    }
}
