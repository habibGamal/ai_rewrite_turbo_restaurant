<?php

namespace App\Services;

use App\Models\ExternalProduct;
use App\Models\ExternalSeller;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

class OutsourceProductImportService
{
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
     * Parse and normalize file content (JSON or Excel) into a standardized structure.
     *
     * @return array{
     *     seller: array{name: ?string, phone: ?string},
     *     products: list<array{name: string, price: float, category: ?string, description: ?string, is_active: bool}>
     * }
     */
    public function parseFile(string $filePath): array
    {
        if (! file_exists($filePath)) {
            throw new Exception("الملف غير موجود: {$filePath}");
        }

        if ($this->isJsonFile($filePath)) {
            return $this->parseJsonFile($filePath);
        }

        return $this->parseExcelFile($filePath);
    }

    /**
     * Parse JSON file.
     *
     * @return array{
     *     seller: array{name: ?string, phone: ?string},
     *     products: list<array{name: string, price: float, category: ?string, description: ?string, is_active: bool}>
     * }
     */
    protected function parseJsonFile(string $filePath): array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new Exception("تعذر قراءة الملف: {$filePath}");
        }

        $data = json_decode($content, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('فشل في قراءة ملف JSON: '.json_last_error_msg());
        }

        if (! is_array($data)) {
            throw new Exception('محتوى ملف JSON غير صالح');
        }

        $sellerName = null;
        $sellerPhone = null;

        if (isset($data['seller']) && is_array($data['seller'])) {
            $sellerName = $data['seller']['name'] ?? $data['seller']['seller_name'] ?? null;
            $sellerPhone = $data['seller']['phone'] ?? $data['seller']['seller_phone'] ?? null;
        } else {
            $sellerName = $data['seller_name'] ?? $data['البائع'] ?? $data['اسم البائع'] ?? null;
            $sellerPhone = $data['seller_phone'] ?? $data['الهاتف'] ?? $data['تليفون'] ?? null;
        }

        $products = [];

        // Check if products are in a dedicated "products" key
        if (isset($data['products']) && is_array($data['products'])) {
            foreach ($data['products'] as $item) {
                if (is_array($item)) {
                    $normalized = $this->normalizeProductRow($item);
                    if ($normalized !== null) {
                        $products[] = $normalized;
                    }
                }
            }
        } elseif (array_is_list($data)) {
            // Flat array of products
            foreach ($data as $item) {
                if (is_array($item)) {
                    if (empty($sellerName) && (! empty($item['seller_name']) || ! empty($item['seller']))) {
                        $sellerName = is_array($item['seller'] ?? null)
                            ? ($item['seller']['name'] ?? null)
                            : ($item['seller_name'] ?? $item['seller'] ?? null);
                    }
                    if (empty($sellerPhone) && (! empty($item['seller_phone']) || ! empty($item['phone']))) {
                        $sellerPhone = $item['seller_phone'] ?? $item['phone'] ?? null;
                    }

                    $normalized = $this->normalizeProductRow($item);
                    if ($normalized !== null) {
                        $products[] = $normalized;
                    }
                }
            }
        } else {
            // Grouped by category keys (e.g., {"RAW": [...], "FRIED": [...]})
            foreach ($data as $key => $items) {
                if ($key === 'seller' || $key === 'seller_name' || $key === 'seller_phone') {
                    continue;
                }
                if (is_array($items)) {
                    foreach ($items as $item) {
                        if (is_array($item)) {
                            if (empty($item['category'])) {
                                $item['category'] = (string) $key;
                            }
                            $normalized = $this->normalizeProductRow($item);
                            if ($normalized !== null) {
                                $products[] = $normalized;
                            }
                        }
                    }
                }
            }
        }

        return [
            'seller' => [
                'name' => is_string($sellerName) && trim($sellerName) !== '' ? trim($sellerName) : null,
                'phone' => is_string($sellerPhone) && trim($sellerPhone) !== '' ? trim($sellerPhone) : null,
            ],
            'products' => $products,
        ];
    }

    /**
     * Parse Excel spreadsheet file.
     *
     * @return array{
     *     seller: array{name: ?string, phone: ?string},
     *     products: list<array{name: string, price: float, category: ?string, description: ?string, is_active: bool}>
     * }
     */
    protected function parseExcelFile(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();

        $highestRow = $worksheet->getHighestRow();
        $highestColumn = $worksheet->getHighestColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

        // Read header row
        $headers = [];
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $val = $worksheet->getCellByColumnAndRow($col, 1)->getCalculatedValue();
            $headers[$col] = is_string($val) ? trim($val) : (string) $val;
        }

        $sellerName = null;
        $sellerPhone = null;
        $products = [];

        for ($row = 2; $row <= $highestRow; $row++) {
            $rowData = [];
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $header = $headers[$col] ?? "col_{$col}";
                $rowData[$header] = $worksheet->getCellByColumnAndRow($col, $row)->getCalculatedValue();
            }

            if (empty(array_filter($rowData))) {
                continue;
            }

            if (empty($sellerName)) {
                $sellerName = $rowData['seller'] ?? $rowData['seller_name'] ?? $rowData['البائع'] ?? $rowData['اسم البائع'] ?? null;
            }
            if (empty($sellerPhone)) {
                $sellerPhone = $rowData['phone'] ?? $rowData['seller_phone'] ?? $rowData['الهاتف'] ?? $rowData['تليفون'] ?? null;
            }

            $normalized = $this->normalizeProductRow($rowData);
            if ($normalized !== null) {
                $products[] = $normalized;
            }
        }

        return [
            'seller' => [
                'name' => is_string($sellerName) && trim($sellerName) !== '' ? trim($sellerName) : null,
                'phone' => is_string($sellerPhone) && trim($sellerPhone) !== '' ? trim($sellerPhone) : null,
            ],
            'products' => $products,
        ];
    }

    /**
     * Normalize a product row into canonical shape.
     *
     * @param  array<string, mixed>  $row
     * @return array{name: string, price: float, category: ?string, description: ?string, is_active: bool}|null
     */
    protected function normalizeProductRow(array $row): ?array
    {
        $name = $row['name']
            ?? $row['product_name']
            ?? $row['أسم المنتج']
            ?? $row['اسم المنتج']
            ?? $row['الصنف']
            ?? $row['اسم الصنف']
            ?? $row['title']
            ?? $row['name_ar']
            ?? null;

        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        $rawPrice = $row['price'] ?? $row['السعر'] ?? $row['cost'] ?? $row['التكلفة'] ?? 0;
        if (! is_numeric($rawPrice)) {
            $rawPrice = 0;
        }
        $price = max(0.0, (float) $rawPrice);

        $category = $row['category'] ?? $row['الفئة'] ?? $row['القسم'] ?? $row['section'] ?? null;
        $description = $row['description'] ?? $row['الوصف'] ?? $row['المكونات'] ?? null;

        $isActive = true;
        if (isset($row['is_active'])) {
            $isActive = filter_var($row['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        } elseif (isset($row['نشط'])) {
            $isActive = filter_var($row['نشط'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        }

        return [
            'name' => trim($name),
            'price' => $price,
            'category' => is_string($category) && trim($category) !== '' ? trim($category) : null,
            'description' => is_string($description) && trim($description) !== '' ? trim($description) : null,
            'is_active' => $isActive,
        ];
    }

    /**
     * Analyze outsource order products file structure before import.
     *
     * @return array<string, mixed>
     */
    public function analyzeStructure(UploadedFile|string $file, ?int $sellerId = null): array
    {
        try {
            $filePath = $this->resolveFilePath($file);
            $parsed = $this->parseFile($filePath);

            $selectedSeller = null;
            if ($sellerId) {
                $selectedSeller = ExternalSeller::find($sellerId);
            }

            $effectiveSellerName = $selectedSeller !== null ? $selectedSeller->name : $parsed['seller']['name'];
            $effectiveSellerPhone = $selectedSeller !== null ? $selectedSeller->phone : $parsed['seller']['phone'];

            $isNewSeller = false;
            if (! empty($effectiveSellerName) && ! $selectedSeller) {
                $existing = ExternalSeller::where('name', $effectiveSellerName)->first();
                $isNewSeller = ($existing === null);
                if ($existing) {
                    $selectedSeller = $existing;
                }
            }

            $categories = [];
            foreach ($parsed['products'] as $prod) {
                if (! empty($prod['category'])) {
                    $categories[$prod['category']] = true;
                }
            }

            // Build sample rows for preview
            $sampleData = [];
            foreach (array_slice($parsed['products'], 0, 10) as $p) {
                $sampleData[] = [
                    'name' => $p['name'],
                    'price' => number_format($p['price'], 2).' EGP',
                    'category' => $p['category'] ?? 'عام',
                    'is_active' => $p['is_active'] ? 'نشط' : 'غير نشط',
                ];
            }

            return [
                'success' => true,
                'seller' => [
                    'id' => $selectedSeller?->id,
                    'name' => $effectiveSellerName,
                    'phone' => $effectiveSellerPhone,
                    'is_new' => $isNewSeller,
                ],
                'total_products' => count($parsed['products']),
                'categories' => array_keys($categories),
                'headers' => ['الاسم', 'السعر', 'الفئة', 'الحالة'],
                'sample_data' => $sampleData,
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Import outsource products into database.
     *
     * @return array<string, mixed>
     */
    public function importData(UploadedFile|string $file, ?int $sellerId = null): array
    {
        try {
            $filePath = $this->resolveFilePath($file);
            $parsed = $this->parseFile($filePath);

            $seller = null;
            if ($sellerId) {
                $seller = ExternalSeller::find($sellerId);
            }

            if (! $seller) {
                $sellerName = $parsed['seller']['name'];
                if (empty($sellerName)) {
                    return [
                        'success' => false,
                        'error' => 'اسم البائع الخارجي مطلوب. يرجى تحديده في الملف أو اختياره من القائمة.',
                    ];
                }

                $seller = ExternalSeller::firstOrCreate(
                    ['name' => $sellerName],
                    [
                        'phone' => $parsed['seller']['phone'] ?? null,
                        'is_active' => true,
                    ]
                );
            }

            $successCount = 0;
            $errorCount = 0;
            $errors = [];

            DB::beginTransaction();

            foreach ($parsed['products'] as $index => $item) {
                $rowNumber = $index + 1;
                try {
                    ExternalProduct::updateOrCreate(
                        [
                            'external_seller_id' => $seller->id,
                            'name' => $item['name'],
                        ],
                        [
                            'price' => $item['price'],
                            'is_active' => $item['is_active'],
                        ]
                    );

                    $successCount++;
                } catch (Exception $e) {
                    $errorCount++;
                    $errors[] = "صنف #{$rowNumber} ({$item['name']}): ".$e->getMessage();
                }
            }

            DB::commit();

            return [
                'success' => true,
                'seller' => [
                    'id' => $seller->id,
                    'name' => $seller->name,
                ],
                'imported_count' => $successCount,
                'error_count' => $errorCount,
                'errors' => $errors,
            ];

        } catch (Exception $e) {
            DB::rollBack();

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
