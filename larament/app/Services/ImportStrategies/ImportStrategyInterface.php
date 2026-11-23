<?php

namespace App\Services\ImportStrategies;

use Illuminate\Http\UploadedFile;

interface ImportStrategyInterface
{
    /**
     * Unique identifier key for this strategy.
     */
    public function getKey(): string;

    /**
     * User-facing label in Arabic.
     */
    public function getLabel(): string;

    /**
     * User-facing description of what this import strategy handles.
     */
    public function getDescription(): string;

    /**
     * Check whether this strategy can handle the given file automatically.
     */
    public function supports(UploadedFile|string $file): bool;

    /**
     * Analyze file structure and return sheet/column metadata for UI preview.
     *
     * @return array{
     *     success: bool,
     *     total_sheets: int,
     *     sheets: array<string, array{
     *         headers: array<int, string>,
     *         sample_data: array<int, array<int, mixed>>,
     *         total_rows: int,
     *         total_columns: int,
     *         estimated_data_rows: int
     *     }>,
     *     error?: string
     * }
     */
    public function analyzeStructure(UploadedFile|string $file): array;

    /**
     * Execute the import process.
     *
     * @return array{
     *     success: bool,
     *     imported_count: int,
     *     error_count: int,
     *     errors: array<int, string>,
     *     sheet_results?: array<string, array{
     *         success_count: int,
     *         error_count: int,
     *         errors: array<int, string>
     *     }>,
     *     error?: string
     * }
     */
    public function import(UploadedFile|string $file): array;
}
