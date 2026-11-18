<?php

namespace App\Services;

use App\Enums\ProductType;
use App\Models\Category;
use App\Models\Printer;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\Warehouse;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

class SpecificDataImportService extends ExcelImportService
{
    /**
     * Canonical sheet / section names in required dependency processing order.
     *
     * @var array<int, string>
     */
    protected array $processingOrder = ['Printer', 'الفئات', 'الخام', 'الاستهلاكي', 'التصنيع', 'المعياري'];

    /**
     * Aliases mapping JSON keys to canonical section names.
     *
     * @var array<string, string>
     */
    protected array $sectionAliases = [
        // Printer
        'printer' => 'Printer',
        'printers' => 'Printer',
        'طابعات' => 'Printer',
        'الطابعات' => 'Printer',
        'طابعة' => 'Printer',

        // Categories
        'categories' => 'الفئات',
        'category' => 'الفئات',
        'فئات' => 'الفئات',
        'الفئات' => 'الفئات',
        'اقسام' => 'الفئات',
        'الأقسام' => 'الفئات',

        // Raw Materials
        'raw_materials' => 'الخام',
        'raw_material' => 'الخام',
        'raw_products' => 'الخام',
        'raw' => 'الخام',
        'الخام' => 'الخام',
        'مواد خام' => 'الخام',
        'المواد الخام' => 'الخام',

        // Consumables
        'consumables' => 'الاستهلاكي',
        'consumable' => 'الاستهلاكي',
        'consumable_products' => 'الاستهلاكي',
        'الاستهلاكي' => 'الاستهلاكي',
        'مستهلكات' => 'الاستهلاكي',
        'المستهلكات' => 'الاستهلاكي',

        // Manufactured Products
        'manufactured_products' => 'التصنيع',
        'manufactured' => 'التصنيع',
        'production' => 'التصنيع',
        'products' => 'التصنيع',
        'التصنيع' => 'التصنيع',
        'منتجات مصنعة' => 'التصنيع',
        'المنتجات المصنعة' => 'التصنيع',

        // Recipes / Standards
        'recipes' => 'المعياري',
        'recipe' => 'المعياري',
        'standards' => 'المعياري',
        'standard' => 'المعياري',
        'components' => 'المعياري',
        'product_components' => 'المعياري',
        'المعياري' => 'المعياري',
        'المكونات' => 'المعياري',
        'المعيار' => 'المعياري',
    ];

    /**
     * Canonical headers for each section.
     *
     * @var array<string, array<int, string>>
     */
    protected array $canonicalHeaders = [
        'Printer' => ['printer_name', 'ipAddr'],
        'الفئات' => ['الفئات'],
        'الخام' => ['أسم المنتج', 'الفئة', 'التكلفة', 'الوحدة'],
        'الاستهلاكي' => ['أسم المنتج', 'الفئة', 'التكلفة', 'السعر', 'الوحدة', 'barcode'],
        'التصنيع' => ['أسم المنتج', 'الفئة', 'السعر', 'barcode'],
        'المعياري' => ['أسم المنتج المصنع', 'أسم المكون', 'الكمية'],
    ];

    /**
     * Resolve the absolute filesystem path from an UploadedFile or string path.
     */
    public function resolveFilePath(UploadedFile|string $file): string
    {
        if ($file instanceof UploadedFile) {
            $realPath = $file->getRealPath();
            if ($realPath && file_exists($realPath)) {
                return $realPath;
            }

            return $file->getPathname();
        }

        if (file_exists($file)) {
            return realpath($file) ?: $file;
        }

        if (Storage::disk('local')->exists($file)) {
            return Storage::disk('local')->path($file);
        }

        if (Storage::exists($file)) {
            return Storage::path($file);
        }

        $appStoragePath = storage_path('app/'.ltrim($file, '/\\'));
        if (file_exists($appStoragePath)) {
            return $appStoragePath;
        }

        $privateStoragePath = storage_path('app/private/'.ltrim($file, '/\\'));
        if (file_exists($privateStoragePath)) {
            return $privateStoragePath;
        }

        return $file;
    }

    /**
     * Check if a given file is a JSON file by extension or content sniffing.
     */
    public function isJsonFile(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'json') {
            return true;
        }

        if (! file_exists($path) || ! is_readable($path)) {
            return false;
        }

        $handle = @fopen($path, 'r');
        if (! $handle) {
            return false;
        }

        $prefix = fread($handle, 512);
        fclose($handle);

        $trimmed = ltrim($prefix ?: '');
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $content = file_get_contents($path);
            if ($content !== false) {
                json_decode($content);

                return json_last_error() === JSON_ERROR_NONE;
            }
        }

        return false;
    }

    /**
     * Normalize section key to canonical section name.
     */
    public function normalizeSectionName(string $name): ?string
    {
        $cleaned = trim(mb_strtolower($name));

        return $this->sectionAliases[$cleaned] ?? ($this->sectionAliases[$name] ?? null);
    }

    /**
     * Parse and normalize a JSON import file into canonical sections and rows.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function parseAndNormalizeJson(string $path): array
    {
        if (! file_exists($path)) {
            throw new Exception("الملف غير موجود: {$path}");
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new Exception("تعذر قراءة الملف: {$path}");
        }

        $data = json_decode($json, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('فشل في قراءة ملف JSON: '.json_last_error_msg());
        }

        if (! is_array($data)) {
            throw new Exception('محتوى ملف JSON غير صالح (يجب أن يكون كائن يحتوي على أقسام البيانات)');
        }

        $normalized = [
            'Printer' => [],
            'الفئات' => [],
            'الخام' => [],
            'الاستهلاكي' => [],
            'التصنيع' => [],
            'المعياري' => [],
        ];

        foreach ($data as $sectionKey => $items) {
            $canonical = $this->normalizeSectionName((string) $sectionKey);
            if (! $canonical || ! is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                $normalizedRow = $this->normalizeRowForSection($canonical, $item);
                if ($normalizedRow !== null) {
                    $normalized[$canonical][] = $normalizedRow;
                }
            }
        }

        return $normalized;
    }

    /**
     * Normalize a single row based on canonical section.
     *
     * @return array<string, mixed>|null
     */
    protected function normalizeRowForSection(string $section, mixed $row): ?array
    {
        switch ($section) {
            case 'Printer':
                if (is_string($row)) {
                    $name = trim($row);

                    return $name !== '' ? ['printer_name' => $name, 'ipAddr' => null] : null;
                }
                if (is_array($row)) {
                    $name = $row['printer_name'] ?? $row['name'] ?? $row['اسم الطابعة'] ?? null;
                    if (is_string($name) && trim($name) !== '') {
                        return [
                            'printer_name' => trim($name),
                            'ipAddr' => $row['ipAddr'] ?? $row['ip_address'] ?? $row['ip'] ?? $row['عنوان ip'] ?? null,
                        ];
                    }
                }

                return null;

            case 'الفئات':
                if (is_string($row)) {
                    $name = trim($row);

                    return $name !== '' ? ['الفئات' => $name] : null;
                }
                if (is_array($row)) {
                    $name = $row['الفئات'] ?? $row['name'] ?? $row['الفئة'] ?? $row['category_name'] ?? $row['category'] ?? null;
                    if (is_string($name) && trim($name) !== '') {
                        return ['الفئات' => trim($name)];
                    }
                }

                return null;

            case 'الخام':
                if (! is_array($row)) {
                    return null;
                }
                $name = $row['أسم المنتج'] ?? $row['اسم المنتج'] ?? $row['name'] ?? $row['product_name'] ?? null;
                if (! is_string($name) || trim($name) === '') {
                    return null;
                }

                return [
                    'أسم المنتج' => trim($name),
                    'الفئة' => $row['الفئة'] ?? $row['category'] ?? null,
                    'التكلفة' => $row['التكلفة'] ?? $row['cost'] ?? null,
                    'الوحدة' => $row['الوحدة'] ?? $row['unit'] ?? null,
                ];

            case 'الاستهلاكي':
                if (! is_array($row)) {
                    return null;
                }
                $name = $row['أسم المنتج'] ?? $row['اسم المنتج'] ?? $row['name'] ?? $row['product_name'] ?? null;
                if (! is_string($name) || trim($name) === '') {
                    return null;
                }

                return [
                    'أسم المنتج' => trim($name),
                    'الفئة' => $row['الفئة'] ?? $row['category'] ?? null,
                    'التكلفة' => $row['التكلفة'] ?? $row['cost'] ?? null,
                    'السعر' => $row['السعر'] ?? $row['price'] ?? null,
                    'الوحدة' => $row['الوحدة'] ?? $row['unit'] ?? null,
                    'barcode' => $row['barcode'] ?? $row['الباركود'] ?? null,
                ];

            case 'التصنيع':
                if (! is_array($row)) {
                    return null;
                }
                $name = $row['أسم المنتج'] ?? $row['اسم المنتج'] ?? $row['name'] ?? $row['product_name'] ?? null;
                if (! is_string($name) || trim($name) === '') {
                    return null;
                }

                return [
                    'أسم المنتج' => trim($name),
                    'الفئة' => $row['الفئة'] ?? $row['category'] ?? null,
                    'السعر' => $row['السعر'] ?? $row['price'] ?? null,
                    'barcode' => $row['barcode'] ?? $row['الباركود'] ?? null,
                ];

            case 'المعياري':
                if (! is_array($row)) {
                    return null;
                }
                $finalProduct = $row['أسم المنتج المصنع'] ?? $row['اسم المنتج المصنع'] ?? $row['manufactured_product'] ?? $row['product'] ?? $row['final_product'] ?? null;
                $component = $row['أسم المكون'] ?? $row['اسم المكون'] ?? $row['component'] ?? $row['raw_material'] ?? null;
                if (! is_string($finalProduct) || trim($finalProduct) === '' || ! is_string($component) || trim($component) === '') {
                    return null;
                }

                return [
                    'أسم المنتج المصنع' => trim($finalProduct),
                    'أسم المكون' => trim($component),
                    'الكمية' => $row['الكمية'] ?? $row['quantity'] ?? null,
                ];

            default:
                return is_array($row) ? $row : null;
        }
    }

    /**
     * Unified structure analyzer accepting UploadedFile or file path string (Excel or JSON).
     */
    public function analyzeStructure(UploadedFile|string $file): array
    {
        $filePath = $this->resolveFilePath($file);

        if ($this->isJsonFile($filePath)) {
            try {
                $normalizedSections = $this->parseAndNormalizeJson($filePath);

                return $this->analyzeJsonStructure($normalizedSections);
            } catch (Exception $e) {
                return [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $this->analyzeExcelStructureFile($filePath);
    }

    /**
     * Backwards-compatible Excel structure analysis.
     */
    public function analyzeExcelStructure(UploadedFile|string $file): array
    {
        return $this->analyzeStructure($file);
    }

    /**
     * Analyze Excel file structure.
     */
    protected function analyzeExcelStructureFile(string $filePath): array
    {
        try {
            $spreadsheet = IOFactory::load($filePath);
            $sheetsAnalysis = [];

            foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
                $sheetName = $worksheet->getTitle();

                $highestRow = $worksheet->getHighestRow();
                $highestColumn = $worksheet->getHighestColumn();
                $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

                $headers = [];
                for ($col = 1; $col <= $highestColumnIndex; $col++) {
                    $cellValue = $worksheet->getCellByColumnAndRow($col, 1)->getCalculatedValue();
                    $headers[] = $cellValue;
                }

                $sampleData = [];
                $maxSampleRows = min(4, $highestRow);

                for ($row = 2; $row <= $maxSampleRows; $row++) {
                    $rowData = [];
                    for ($col = 1; $col <= $highestColumnIndex; $col++) {
                        $cellValue = $worksheet->getCellByColumnAndRow($col, $row)->getCalculatedValue();
                        $rowData[] = $cellValue;
                    }
                    if (! empty(array_filter($rowData))) {
                        $sampleData[] = $rowData;
                    }
                }

                $sheetsAnalysis[$sheetName] = [
                    'headers' => $headers,
                    'sample_data' => $sampleData,
                    'total_rows' => $highestRow,
                    'total_columns' => $highestColumnIndex,
                    'estimated_data_rows' => max(0, $highestRow - 1),
                ];
            }

            return [
                'success' => true,
                'sheets' => $sheetsAnalysis,
                'total_sheets' => count($sheetsAnalysis),
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Analyze JSON structure, building the same sheets structure expected by views.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $normalizedSections
     */
    public function analyzeJsonStructure(array $normalizedSections): array
    {
        $sheetsAnalysis = [];

        foreach ($this->processingOrder as $sheetName) {
            $rows = $normalizedSections[$sheetName] ?? [];
            $headers = $this->canonicalHeaders[$sheetName] ?? [];

            $sampleData = [];
            $sampleRows = array_slice($rows, 0, 3);
            foreach ($sampleRows as $row) {
                $rowCells = [];
                foreach ($headers as $header) {
                    $rowCells[] = $row[$header] ?? null;
                }
                $sampleData[] = $rowCells;
            }

            $count = count($rows);
            $sheetsAnalysis[$sheetName] = [
                'headers' => $headers,
                'sample_data' => $sampleData,
                'total_rows' => $count > 0 ? $count + 1 : 0,
                'total_columns' => count($headers),
                'estimated_data_rows' => $count,
            ];
        }

        return [
            'success' => true,
            'sheets' => $sheetsAnalysis,
            'total_sheets' => count($sheetsAnalysis),
        ];
    }

    /**
     * Unified data importer accepting UploadedFile or file path string (Excel or JSON).
     */
    public function importData(UploadedFile|string $file): array
    {
        $filePath = $this->resolveFilePath($file);

        if ($this->isJsonFile($filePath)) {
            try {
                $normalizedSections = $this->parseAndNormalizeJson($filePath);

                return $this->importJsonSections($normalizedSections);
            } catch (Exception $e) {
                return [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $this->importExcelDataFile($filePath);
    }

    /**
     * Backwards-compatible Excel import entry point.
     */
    public function importExcelData(UploadedFile|string $file): array
    {
        return $this->importData($file);
    }

    /**
     * Ensure a default warehouse exists before importing items that create InventoryItems.
     */
    protected function ensureDefaultWarehouse(): void
    {
        if (! Warehouse::where('is_default', true)->exists()) {
            Warehouse::create([
                'name' => 'المخزن الرئيسي',
                'is_default' => true,
                'is_active' => true,
            ]);
        }
    }

    /**
     * Import normalized JSON sections.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $normalizedSections
     */
    public function importJsonSections(array $normalizedSections): array
    {
        try {
            $this->ensureDefaultWarehouse();

            $results = [];
            $totalSuccess = 0;
            $totalErrors = 0;
            $allErrors = [];

            DB::beginTransaction();

            foreach ($this->processingOrder as $sheetName) {
                $rows = $normalizedSections[$sheetName] ?? [];
                $sheetSuccess = 0;
                $sheetErrors = 0;
                $sheetErrorMessages = [];

                foreach ($rows as $index => $row) {
                    $rowNumber = $index + 1;
                    try {
                        $result = $this->processSheetRow($row, $sheetName, $rowNumber);
                        if ($result['success']) {
                            $sheetSuccess++;
                        } else {
                            $sheetErrors++;
                            $sheetErrorMessages[] = "{$sheetName} - Row {$rowNumber}: ".($result['error'] ?? 'خطأ غير محدد');
                        }
                    } catch (Exception $e) {
                        $sheetErrors++;
                        $sheetErrorMessages[] = "{$sheetName} - Row {$rowNumber}: ".$e->getMessage();
                    }
                }

                $results[$sheetName] = [
                    'success_count' => $sheetSuccess,
                    'error_count' => $sheetErrors,
                    'errors' => $sheetErrorMessages,
                ];

                $totalSuccess += $sheetSuccess;
                $totalErrors += $sheetErrors;
                $allErrors = array_merge($allErrors, $sheetErrorMessages);
            }

            DB::commit();

            return [
                'success' => true,
                'imported_count' => $totalSuccess,
                'error_count' => $totalErrors,
                'errors' => $allErrors,
                'sheet_results' => $results,
            ];

        } catch (Exception $e) {
            DB::rollBack();

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Import data from Excel spreadsheet file.
     */
    protected function importExcelDataFile(string $filePath): array
    {
        try {
            $this->ensureDefaultWarehouse();

            $spreadsheet = IOFactory::load($filePath);

            $results = [];
            $totalSuccess = 0;
            $totalErrors = 0;
            $allErrors = [];

            DB::beginTransaction();

            foreach ($this->processingOrder as $sheetName) {
                try {
                    $worksheet = $spreadsheet->getSheetByName($sheetName);
                    if (! $worksheet) {
                        $results[$sheetName] = [
                            'success_count' => 0,
                            'error_count' => 0,
                            'errors' => ["الورقة '{$sheetName}' غير موجودة"],
                        ];

                        continue;
                    }

                    $result = $this->processSheet($worksheet, $sheetName);
                    $results[$sheetName] = $result;

                    $totalSuccess += $result['success_count'];
                    $totalErrors += $result['error_count'];
                    $allErrors = array_merge($allErrors, $result['errors']);
                } catch (Exception $e) {
                    $results[$sheetName] = [
                        'success_count' => 0,
                        'error_count' => 1,
                        'errors' => ["خطأ في معالجة الورقة '{$sheetName}': ".$e->getMessage()],
                    ];
                    $totalErrors++;
                    $allErrors[] = "خطأ في معالجة الورقة '{$sheetName}': ".$e->getMessage();
                }
            }

            DB::commit();

            return [
                'success' => true,
                'imported_count' => $totalSuccess,
                'error_count' => $totalErrors,
                'errors' => $allErrors,
                'sheet_results' => $results,
            ];

        } catch (Exception $e) {
            DB::rollBack();

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process a single sheet based on its type
     */
    private function processSheet($worksheet, string $sheetName): array
    {
        $highestRow = $worksheet->getHighestRow();
        $highestColumn = $worksheet->getHighestColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

        // Get headers
        $headers = [];
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $cellValue = $worksheet->getCellByColumnAndRow($col, 1)->getCalculatedValue();
            $headers[] = $cellValue;
        }

        $successCount = 0;
        $errorCount = 0;
        $errors = [];

        // Process data rows
        for ($row = 2; $row <= $highestRow; $row++) {
            try {
                $rowData = [];

                // Map column data to headers
                for ($col = 1; $col <= $highestColumnIndex; $col++) {
                    $headerIndex = $col - 1;
                    $cellValue = $worksheet->getCellByColumnAndRow($col, $row)->getCalculatedValue();

                    if (isset($headers[$headerIndex])) {
                        $rowData[$headers[$headerIndex]] = $cellValue;
                    }
                }

                // Skip empty rows
                if (empty(array_filter($rowData))) {
                    continue;
                }

                $result = $this->processSheetRow($rowData, $sheetName, $row);

                if ($result['success']) {
                    $successCount++;
                } else {
                    $errors[] = "{$sheetName} - Row {$row}: ".$result['error'];
                    $errorCount++;
                }

            } catch (Exception $e) {
                $errors[] = "{$sheetName} - Row {$row}: ".$e->getMessage();
                $errorCount++;
            }
        }

        return [
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'errors' => $errors,
        ];
    }

    /**
     * Process a single row based on sheet type
     */
    private function processSheetRow(array $rowData, string $sheetName, int $rowNumber): array
    {
        switch ($sheetName) {
            case 'Printer':
                return $this->processPrinterRow($rowData);
            case 'الفئات':
                return $this->processCategoryRow($rowData);
            case 'الخام':
                return $this->processRawProductRow($rowData);
            case 'الاستهلاكي':
                return $this->processConsumableRow($rowData);
            case 'التصنيع':
                return $this->processProductionRow($rowData);
            case 'المعياري':
                return $this->processStandardRow($rowData);
            default:
                return [
                    'success' => true,
                    'data' => $rowData,
                ];
        }
    }

    /**
     * Process printer data
     */
    private function processPrinterRow(array $data): array
    {
        try {
            $printerName = $data['printer_name'] ?? $data['name'] ?? $data['اسم الطابعة'] ?? null;
            $ipAddress = $data['ipAddr'] ?? $data['ip_address'] ?? $data['ip'] ?? $data['عنوان ip'] ?? null;

            if (is_string($printerName)) {
                $printerName = trim($printerName);
            }

            if (empty($printerName)) {
                return ['success' => false, 'error' => 'اسم الطابعة مطلوب'];
            }

            $printer = Printer::updateOrCreate(
                ['name' => $printerName],
                [
                    'name' => $printerName,
                    'ip_address' => $ipAddress,
                ]
            );

            return [
                'success' => true,
                'data' => ['printer_id' => $printer->id, 'name' => $printer->name],
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Process category data
     */
    private function processCategoryRow(array $data): array
    {
        try {
            $categoryName = $data['الفئات'] ?? $data['name'] ?? $data['الفئة'] ?? $data['category_name'] ?? $data['category'] ?? null;

            if (is_string($categoryName)) {
                $categoryName = trim($categoryName);
            }

            if (empty($categoryName)) {
                return ['success' => false, 'error' => 'اسم الفئة مطلوب'];
            }

            $category = Category::updateOrCreate(
                ['name' => $categoryName],
                ['name' => $categoryName]
            );

            return [
                'success' => true,
                'data' => ['category_id' => $category->id, 'name' => $category->name],
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Process raw product data
     */
    private function processRawProductRow(array $data): array
    {
        try {
            $productName = $data['أسم المنتج'] ?? $data['اسم المنتج'] ?? $data['name'] ?? $data['product_name'] ?? null;

            if (is_string($productName)) {
                $productName = trim($productName);
            }

            if (empty($productName)) {
                return ['success' => false, 'error' => 'اسم المنتج مطلوب'];
            }

            // Get or create category
            $categoryName = $data['الفئة'] ?? $data['category'] ?? null;
            $categoryId = null;
            if (! empty($categoryName)) {
                $category = Category::firstOrCreate(['name' => trim((string) $categoryName)]);
                $categoryId = $category->id;
            }

            $cost = $data['التكلفة'] ?? $data['cost'] ?? null;
            $unit = $data['الوحدة'] ?? $data['unit'] ?? null;

            $product = Product::updateOrCreate(
                ['name' => $productName],
                [
                    'name' => $productName,
                    'cost' => $cost !== null ? (float) $cost : null,
                    'price' => $cost !== null ? (float) $cost : null,
                    'unit' => $unit,
                    'category_id' => $categoryId,
                    'type' => ProductType::RawMaterial,
                ]
            );

            return [
                'success' => true,
                'data' => ['product_id' => $product->id, 'name' => $product->name, 'type' => 'raw'],
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Process production product data
     */
    private function processProductionRow(array $data): array
    {
        try {
            $productName = $data['أسم المنتج'] ?? $data['اسم المنتج'] ?? $data['name'] ?? $data['product_name'] ?? null;

            if (is_string($productName)) {
                $productName = trim($productName);
            }

            if (empty($productName)) {
                return ['success' => false, 'error' => 'اسم المنتج مطلوب'];
            }

            // Get or create category
            $categoryName = $data['الفئة'] ?? $data['category'] ?? null;
            $categoryId = null;
            if (! empty($categoryName)) {
                $category = Category::firstOrCreate(['name' => trim((string) $categoryName)]);
                $categoryId = $category->id;
            }

            $price = $data['السعر'] ?? $data['price'] ?? null;
            $barcode = $data['barcode'] ?? $data['الباركود'] ?? null;

            $product = Product::updateOrCreate(
                ['name' => $productName],
                [
                    'name' => $productName,
                    'price' => $price !== null ? (float) $price : null,
                    'cost' => 0,
                    'unit' => 'باكت',
                    'category_id' => $categoryId,
                    'type' => ProductType::Manufactured,
                    'barcode' => $barcode,
                ]
            );

            return [
                'success' => true,
                'data' => ['product_id' => $product->id, 'name' => $product->name, 'type' => 'production', 'barcode' => $barcode],
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Process consumable product data
     */
    private function processConsumableRow(array $data): array
    {
        try {
            $productName = $data['أسم المنتج'] ?? $data['اسم المنتج'] ?? $data['name'] ?? $data['product_name'] ?? null;

            if (is_string($productName)) {
                $productName = trim($productName);
            }

            if (empty($productName)) {
                return ['success' => false, 'error' => 'اسم المنتج مطلوب'];
            }

            // Get or create category
            $categoryName = $data['الفئة'] ?? $data['category'] ?? null;
            $categoryId = null;
            if (! empty($categoryName)) {
                $category = Category::firstOrCreate(['name' => trim((string) $categoryName)]);
                $categoryId = $category->id;
            }

            $cost = $data['التكلفة'] ?? $data['cost'] ?? null;
            $price = $data['السعر'] ?? $data['price'] ?? null;
            $unit = $data['الوحدة'] ?? $data['unit'] ?? null;
            $barcode = $data['barcode'] ?? $data['الباركود'] ?? null;

            $product = Product::updateOrCreate(
                ['name' => $productName],
                [
                    'name' => $productName,
                    'cost' => $cost !== null ? (float) $cost : null,
                    'price' => $price !== null ? (float) $price : null,
                    'unit' => $unit,
                    'category_id' => $categoryId,
                    'type' => ProductType::Consumable,
                    'barcode' => $barcode,
                ]
            );

            return [
                'success' => true,
                'data' => ['product_id' => $product->id, 'name' => $product->name, 'type' => 'consumable', 'barcode' => $barcode],
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Process standard recipe data (product components)
     */
    private function processStandardRow(array $data): array
    {
        try {
            $finalProductName = $data['أسم المنتج المصنع'] ?? $data['اسم المنتج المصنع'] ?? $data['manufactured_product'] ?? $data['product'] ?? $data['final_product'] ?? null;
            $componentName = $data['أسم المكون'] ?? $data['اسم المكون'] ?? $data['component'] ?? $data['raw_material'] ?? null;
            $quantity = $data['الكمية'] ?? $data['quantity'] ?? null;

            if (is_string($finalProductName)) {
                $finalProductName = trim($finalProductName);
            }
            if (is_string($componentName)) {
                $componentName = trim($componentName);
            }

            if (empty($finalProductName) || empty($componentName) || ! is_numeric($quantity)) {
                return ['success' => false, 'error' => 'اسم المنتج النهائي والمكون والكمية مطلوبة'];
            }

            // Find the final product
            $finalProduct = Product::where('name', $finalProductName)->first();
            if (! $finalProduct) {
                return ['success' => false, 'error' => "المنتج النهائي '{$finalProductName}' غير موجود"];
            }

            // Find the component
            $component = Product::where('name', $componentName)->first();
            if (! $component) {
                return ['success' => false, 'error' => "المكون '{$componentName}' غير موجود"];
            }

            // Create or update the component relationship
            ProductComponent::updateOrCreate(
                [
                    'product_id' => $finalProduct->id,
                    'component_id' => $component->id,
                ],
                [
                    'quantity' => (float) $quantity,
                ]
            );

            // Update the final product's cost based on components
            $components = $finalProduct->components()->get();
            $totalCost = $components->sum(function ($comp) {
                return (float) ($comp->pivot->quantity ?? 0) * (float) ($comp->cost ?? 0);
            });
            $finalProduct->cost = $totalCost;
            $finalProduct->save();

            return [
                'success' => true,
                'data' => [
                    'final_product' => $finalProduct->name,
                    'component' => $component->name,
                    'quantity' => (float) $quantity,
                ],
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
