<?php

namespace App\Services\ImportStrategies;

use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

abstract class AbstractImportStrategy implements ImportStrategyInterface
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
     * Read and decode JSON content from file.
     */
    public function readJson(string $path): mixed
    {
        if (! file_exists($path)) {
            throw new Exception("الملف غير موجود: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new Exception("تعذر قراءة ملف JSON: {$path}");
        }

        $data = json_decode($content, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('فشل في قراءة ملف JSON: '.json_last_error_msg());
        }

        return $data;
    }

    /**
     * Load spreadsheet instance using PhpSpreadsheet.
     */
    public function loadSpreadsheet(string $path): Spreadsheet
    {
        if (! file_exists($path)) {
            throw new Exception("الملف غير موجود: {$path}");
        }

        return IOFactory::load($path);
    }

    /**
     * Clean and normalize cell values.
     */
    public function cleanValue(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $cleaned = trim($value);
            if ($cleaned === '') {
                return null;
            }

            if (is_numeric($cleaned)) {
                return is_float($cleaned + 0) ? (float) $cleaned : (int) $cleaned;
            }

            return $cleaned;
        }

        return $value;
    }

    /**
     * Normalize header names for consistent processing.
     */
    public function normalizeHeader(string $header): string
    {
        $normalized = strtolower(trim($header));
        $normalized = preg_replace('/[^a-z0-9\x{0621}-\x{064A}]+/u', '_', $normalized) ?? $normalized;

        return trim($normalized, '_');
    }

    /**
     * Helper to read rows from worksheet.
     *
     * @return array{headers: array<int, string>, rows: array<int, array<string, mixed>>}
     */
    protected function extractWorksheetData(Worksheet $worksheet): array
    {
        $highestRow = $worksheet->getHighestRow();
        $highestColumn = $worksheet->getHighestColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

        $headers = [];
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $cellValue = $worksheet->getCellByColumnAndRow($col, 1)->getCalculatedValue();
            $headers[] = $cellValue !== null ? trim((string) $cellValue) : '';
        }

        $rows = [];
        for ($row = 2; $row <= $highestRow; $row++) {
            $rowData = [];
            $hasData = false;
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $header = $headers[$col - 1] ?? "col_{$col}";
                $val = $worksheet->getCellByColumnAndRow($col, $row)->getCalculatedValue();
                if ($val !== null && trim((string) $val) !== '') {
                    $hasData = true;
                }
                $rowData[$header] = $val;
            }
            if ($hasData) {
                $rows[] = $rowData;
            }
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
        ];
    }
}
