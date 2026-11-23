<?php

namespace App\Services\ImportStrategies;

use App\Services\SpecificDataImportService;
use Exception;
use Illuminate\Http\UploadedFile;

class ComprehensiveKitchenImportStrategy extends AbstractImportStrategy
{
    protected SpecificDataImportService $service;

    public function __construct(?SpecificDataImportService $service = null)
    {
        $this->service = $service ?? new SpecificDataImportService;
    }

    public function getKey(): string
    {
        return 'kitchen_structure';
    }

    public function getLabel(): string
    {
        return 'هيكل المطعم الشامل (طابعات، فئات، خامات، تصنيع، معياري)';
    }

    public function getDescription(): string
    {
        return 'استيراد بيانات المطعم المتكاملة بما فيها الطابعات، الفئات، المواد الخام، المنتجات المصنعة ومعايير التصنيع.';
    }

    public function supports(UploadedFile|string $file): bool
    {
        try {
            $filePath = $this->resolveFilePath($file);

            if ($this->isJsonFile($filePath)) {
                $data = $this->readJson($filePath);
                if (! is_array($data)) {
                    return false;
                }

                $kitchenKeys = [
                    'printer', 'printers', 'طابعات', 'الفئات', 'categories', 'category',
                    'الخام', 'raw_materials', 'raw', 'التصنيع', 'manufactured_products',
                    'المعياري', 'recipes', 'standards',
                ];

                $matched = 0;
                foreach (array_keys($data) as $key) {
                    if (in_array(mb_strtolower((string) $key), $kitchenKeys, true)) {
                        $matched++;
                    }
                }

                return $matched >= 2;
            }

            // For spreadsheet
            $spreadsheet = $this->loadSpreadsheet($filePath);
            $sheetNames = $spreadsheet->getSheetNames();
            $knownSheets = ['Printer', 'الفئات', 'الخام', 'الاستهلاكي', 'التصنيع', 'المعياري'];

            $matching = array_intersect($sheetNames, $knownSheets);

            return count($matching) >= 2;

        } catch (Exception) {
            return false;
        }
    }

    public function analyzeStructure(UploadedFile|string $file): array
    {
        return $this->service->analyzeStructure($file);
    }

    public function import(UploadedFile|string $file): array
    {
        return $this->service->importData($file);
    }
}
