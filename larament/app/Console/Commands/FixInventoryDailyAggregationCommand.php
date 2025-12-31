<?php

namespace App\Console\Commands;

use App\Models\InventoryItem;
use App\Models\InventoryItemMovement;
use App\Models\InventoryItemMovementDaily;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FixInventoryDailyAggregationCommand extends Command
{
    protected $signature = 'inventory:fix-daily-aggregation
                            {--date= : Specific date to fix (Y-m-d format). If not provided, fixes open day}
                            {--all : Fix all days, not just open day}
                            {--product= : Fix specific product ID only}
                            {--dry-run : Run without making changes}
                            {--fix-start-quantity : Recalculate start_quantity from previous day end_quantity}
                            {--fix-end-quantity : Recalculate end_quantity from current inventory (for open days)}
                            {--create-missing : Create missing daily records for products with inventory}
                            {--verbose-report : Show detailed report of changes}';

    protected $description = 'Fix and recalculate InventoryItemMovementDaily records from inventory movements';

    protected bool $isDryRun = false;
    protected bool $verboseReport = false;
    protected array $stats = [
        'records_updated' => 0,
        'records_created' => 0,
        'records_skipped' => 0,
        'errors' => 0,
    ];

    public function handle(): int
    {
        $this->isDryRun = $this->option('dry-run');
        $this->verboseReport = $this->option('verbose-report');

        if ($this->isDryRun) {
            $this->warn('🔍 وضع المحاكاة - لن يتم حفظ أي تغييرات');
        }

        $this->info('🔧 بدء إصلاح سجلات حركة المخزون اليومية...');
        $this->newLine();

        try {
            // Determine which days to process
            $daysToProcess = $this->getDaysToProcess();

            if ($daysToProcess->isEmpty()) {
                $this->warn('لا توجد أيام لمعالجتها.');
                return 0;
            }

            $this->info("📅 عدد الأيام للمعالجة: {$daysToProcess->count()}");
            $this->newLine();

            $productId = $this->option('product');

            foreach ($daysToProcess as $dayData) {
                $this->processDay($dayData, $productId);
            }

            // Create missing records if requested
            if ($this->option('create-missing')) {
                $this->createMissingRecords($productId);
            }

            $this->showSummary();

            return 0;

        } catch (\Exception $e) {
            $this->error("❌ فشل الإصلاح: {$e->getMessage()}");
            Log::error("Fix inventory daily aggregation failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }

    /**
     * Determine which days need to be processed
     */
    protected function getDaysToProcess()
    {
        $specificDate = $this->option('date');
        $fixAll = $this->option('all');

        if ($specificDate) {
            try {
                $date = Carbon::parse($specificDate);
                return collect([
                    [
                        'date' => $date,
                        'is_open' => InventoryItemMovementDaily::where('date', $date)->whereNull('closed_at')->exists()
                    ]
                ]);
            } catch (\Exception $e) {
                throw new \InvalidArgumentException("تاريخ غير صالح: {$specificDate}");
            }
        }

        if ($fixAll) {
            // Get all unique dates from daily movement records
            return InventoryItemMovementDaily::select('date')
                ->selectRaw('MIN(closed_at) IS NULL as is_open')
                ->groupBy('date')
                ->orderBy('date', 'asc')
                ->get()
                ->map(function ($record) {
                    return [
                        'date' => Carbon::parse($record->date),
                        'is_open' => (bool) $record->is_open
                    ];
                });
        }

        // Default: only open day
        $openDay = InventoryItemMovementDaily::whereNull('closed_at')
            ->orderBy('date', 'desc')
            ->first();

        if (!$openDay) {
            return collect([]);
        }

        return collect([
            [
                'date' => Carbon::parse($openDay->date),
                'is_open' => true
            ]
        ]);
    }

    /**
     * Process a single day's aggregation
     */
    protected function processDay(array $dayData, ?string $productId = null): void
    {
        $date = $dayData['date'];
        $isOpen = $dayData['is_open'];
        $dateString = $date->toDateString();

        $this->info("📆 معالجة يوم: {$dateString} " . ($isOpen ? '(مفتوح)' : '(مغلق)'));

        DB::beginTransaction();

        try {
            // Get the daily record for determining the time window
            $dailyRecord = InventoryItemMovementDaily::where('date', $date)
                ->orderBy('created_at', 'asc')
                ->first();

            if (!$dailyRecord) {
                $this->warn("  ⚠️ لا توجد سجلات لهذا اليوم");
                DB::rollBack();
                return;
            }

            // Get records to process
            $query = InventoryItemMovementDaily::where('date', $date);
            if ($productId) {
                $query->where('product_id', $productId);
            }
            $records = $query->get();

            if ($records->isEmpty()) {
                $this->warn("  ⚠️ لا توجد سجلات للمعالجة");
                DB::rollBack();
                return;
            }

            $productIds = $records->pluck('product_id')->toArray();

            // Calculate aggregated movements for all products
            $aggregatedMovements = $this->calculateAggregatedMovements(
                $productIds,
                $dailyRecord->created_at,
                $isOpen ? null : $dailyRecord->closed_at
            );

            // Update each record
            foreach ($records as $record) {
                $this->updateDailyRecord($record, $aggregatedMovements, $isOpen);
            }

            if (!$this->isDryRun) {
                DB::commit();
            } else {
                DB::rollBack();
            }

            $this->info("  ✅ تم معالجة {$records->count()} سجل");

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("  ❌ خطأ في معالجة اليوم {$dateString}: {$e->getMessage()}");
            $this->stats['errors']++;
            Log::error("Error processing day {$dateString}", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Calculate aggregated movements for products within a time window
     */
    protected function calculateAggregatedMovements(array $productIds, $startTime, $endTime = null)
    {
        $query = DB::table('inventory_item_movements as m')
            ->select([
                'm.product_id',
                DB::raw("SUM(CASE
                    WHEN m.operation = 'in' AND m.reason = 'purchase'
                    THEN m.quantity
                    ELSE 0
                END) as incoming_quantity"),
                DB::raw("SUM(CASE
                    WHEN m.operation = 'in' AND m.reason = 'order_return'
                    THEN m.quantity
                    ELSE 0
                END) as return_sales_quantity"),
                DB::raw("SUM(CASE
                    WHEN m.operation = 'out' AND m.reason = 'order'
                    THEN m.quantity
                    ELSE 0
                END) as sales_quantity"),
                DB::raw("SUM(CASE
                    WHEN m.operation = 'out' AND m.reason IN ('waste', 'purchase_return')
                    THEN m.quantity
                    ELSE 0
                END) as return_waste_quantity"),
                // Also calculate total in/out for verification
                DB::raw("SUM(CASE WHEN m.operation = 'in' THEN m.quantity ELSE 0 END) as total_in"),
                DB::raw("SUM(CASE WHEN m.operation = 'out' THEN m.quantity ELSE 0 END) as total_out"),
            ])
            ->whereIn('m.product_id', $productIds)
            ->where('m.created_at', '>=', $startTime);

        if ($endTime) {
            $query->where('m.created_at', '<=', $endTime);
        }

        return $query->groupBy('m.product_id')
            ->get()
            ->keyBy('product_id');
    }

    /**
     * Update a single daily record with aggregated data
     */
    protected function updateDailyRecord($record, $aggregatedMovements, bool $isOpen): void
    {
        $productId = $record->product_id;
        $movements = $aggregatedMovements->get($productId);

        $changes = [];
        $oldValues = [];

        // Calculate new values
        $newIncoming = $movements ? (float) $movements->incoming_quantity : 0;
        $newReturnSales = $movements ? (float) $movements->return_sales_quantity : 0;
        $newSales = $movements ? (float) $movements->sales_quantity : 0;
        $newReturnWaste = $movements ? (float) $movements->return_waste_quantity : 0;

        // Check and update aggregation values
        if ($record->incoming_quantity != $newIncoming) {
            $oldValues['incoming_quantity'] = $record->incoming_quantity;
            $changes['incoming_quantity'] = $newIncoming;
        }

        if ($record->return_sales_quantity != $newReturnSales) {
            $oldValues['return_sales_quantity'] = $record->return_sales_quantity;
            $changes['return_sales_quantity'] = $newReturnSales;
        }

        if ($record->sales_quantity != $newSales) {
            $oldValues['sales_quantity'] = $record->sales_quantity;
            $changes['sales_quantity'] = $newSales;
        }

        if ($record->return_waste_quantity != $newReturnWaste) {
            $oldValues['return_waste_quantity'] = $record->return_waste_quantity;
            $changes['return_waste_quantity'] = $newReturnWaste;
        }

        // Fix start_quantity if requested
        if ($this->option('fix-start-quantity')) {
            $correctStartQty = $this->calculateCorrectStartQuantity($productId, $record->date);
            if ($record->start_quantity != $correctStartQty) {
                $oldValues['start_quantity'] = $record->start_quantity;
                $changes['start_quantity'] = $correctStartQty;
            }
        }

        // Fix end_quantity for open days if requested
        if ($this->option('fix-end-quantity') && $isOpen) {
            $currentInventory = InventoryItem::where('product_id', $productId)->first();
            $correctEndQty = $currentInventory ? (float) $currentInventory->quantity : 0;
            if ($record->end_quantity != $correctEndQty) {
                $oldValues['end_quantity'] = $record->end_quantity;
                $changes['end_quantity'] = $correctEndQty;
            }
        }

        if (empty($changes)) {
            $this->stats['records_skipped']++;
            if ($this->verboseReport) {
                $this->line("    ⏭️ منتج #{$productId}: لا توجد تغييرات");
            }
            return;
        }

        // Apply changes
        if (!$this->isDryRun) {
            $record->update($changes);
        }

        $this->stats['records_updated']++;

        if ($this->verboseReport) {
            $this->line("    ✏️ منتج #{$productId}:");
            foreach ($changes as $field => $newValue) {
                $oldValue = $oldValues[$field] ?? 'N/A';
                $this->line("       {$field}: {$oldValue} → {$newValue}");
            }
        }
    }

    /**
     * Calculate the correct start quantity from previous day's end quantity
     */
    protected function calculateCorrectStartQuantity(int $productId, $date): float
    {
        $previousRecord = InventoryItemMovementDaily::where('product_id', $productId)
            ->where('date', '<', $date)
            ->orderBy('date', 'desc')
            ->first();

        if ($previousRecord) {
            return (float) $previousRecord->end_quantity;
        }

        // If no previous record, check if there's an initial inventory
        $inventoryItem = InventoryItem::where('product_id', $productId)->first();

        // Get movements before this date to calculate what the starting should have been
        $movementsBefore = DB::table('inventory_item_movements')
            ->where('product_id', $productId)
            ->where('created_at', '<', $date)
            ->selectRaw("
                SUM(CASE WHEN operation = 'in' THEN quantity ELSE 0 END) as total_in,
                SUM(CASE WHEN operation = 'out' THEN quantity ELSE 0 END) as total_out
            ")
            ->first();

        $totalIn = $movementsBefore->total_in ?? 0;
        $totalOut = $movementsBefore->total_out ?? 0;

        return (float) ($totalIn - $totalOut);
    }

    /**
     * Create missing daily records for products with inventory but no daily records
     */
    protected function createMissingRecords(?string $productId = null): void
    {
        $this->info('🔍 البحث عن سجلات مفقودة...');

        // Get the open day date
        $openDay = InventoryItemMovementDaily::whereNull('closed_at')
            ->orderBy('date', 'desc')
            ->first();

        if (!$openDay) {
            $this->warn('  ⚠️ لا يوجد يوم مفتوح');
            return;
        }

        $openDayDate = Carbon::parse($openDay->date);

        // Get products with inventory that don't have daily records for today
        $query = Product::whereHas('inventoryItem');
        if ($productId) {
            $query->where('id', $productId);
        }

        $productsWithInventory = $query->pluck('id');

        $existingRecords = InventoryItemMovementDaily::where('date', $openDayDate)
            ->whereIn('product_id', $productsWithInventory)
            ->pluck('product_id');

        $missingProductIds = $productsWithInventory->diff($existingRecords);

        if ($missingProductIds->isEmpty()) {
            $this->info('  ✅ لا توجد سجلات مفقودة');
            return;
        }

        $this->info("  📝 إنشاء {$missingProductIds->count()} سجل مفقود...");

        foreach ($missingProductIds as $missingProductId) {
            $startQuantity = $this->calculateCorrectStartQuantity($missingProductId, $openDayDate);

            // Calculate movements for this product since the day opened
            $movements = $this->calculateAggregatedMovements(
                [$missingProductId],
                $openDay->created_at,
                null
            )->get($missingProductId);

            $data = [
                'product_id' => $missingProductId,
                'date' => $openDayDate,
                'start_quantity' => $startQuantity,
                'incoming_quantity' => $movements ? (float) $movements->incoming_quantity : 0,
                'return_sales_quantity' => $movements ? (float) $movements->return_sales_quantity : 0,
                'sales_quantity' => $movements ? (float) $movements->sales_quantity : 0,
                'return_waste_quantity' => $movements ? (float) $movements->return_waste_quantity : 0,
                'end_quantity' => $startQuantity, // Will be updated when day closes
                'closed_at' => null,
            ];

            if (!$this->isDryRun) {
                InventoryItemMovementDaily::create($data);
            }

            $this->stats['records_created']++;

            if ($this->verboseReport) {
                $this->line("    ➕ تم إنشاء سجل للمنتج #{$missingProductId}");
            }
        }
    }

    /**
     * Show summary of changes
     */
    protected function showSummary(): void
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════');
        $this->info('📊 ملخص الإصلاح:');
        $this->info('═══════════════════════════════════════');
        $this->line("  ✏️  السجلات المحدّثة: {$this->stats['records_updated']}");
        $this->line("  ➕ السجلات المنشأة: {$this->stats['records_created']}");
        $this->line("  ⏭️  السجلات بدون تغيير: {$this->stats['records_skipped']}");
        $this->line("  ❌ الأخطاء: {$this->stats['errors']}");
        $this->info('═══════════════════════════════════════');

        if ($this->isDryRun) {
            $this->warn('⚠️ هذه محاكاة - لم يتم حفظ أي تغييرات');
        }
    }
}
