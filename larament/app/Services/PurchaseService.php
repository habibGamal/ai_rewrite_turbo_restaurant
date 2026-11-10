<?php

namespace App\Services;

use App\Enums\MovementReason;
use App\Models\PurchaseInvoice;
use App\Models\ReturnPurchaseInvoice;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseService
{
    protected StockService $stockService;

    public function __construct(StockService $stockService)
    {
        $this->stockService = $stockService;
    }

    /**
     * Close a purchase invoice and add items to stock
     */
    public function closePurchaseInvoice(PurchaseInvoice $invoice): bool
    {
        if ($invoice->closed_at) {
            throw new Exception('هذه الفاتورة مغلقة بالفعل');
        }

        shouldDayBeOpen();

        try {
            DB::beginTransaction();

            $locked = PurchaseInvoice::whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->closed_at) {
                throw new Exception('هذه الفاتورة مغلقة بالفعل');
            }

            // Get all items for this invoice
            $items = $locked->items()->with('product')->orderBy('id')->lockForUpdate()->get();

            if ($items->isEmpty()) {
                throw new Exception('لا توجد أصناف في هذه الفاتورة');
            }

            // Prepare items for stock processing
            $stockItems = $items->map(function ($item) use ($locked) {
                return [
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'unit_cost' => (float) $item->price,
                    'warehouse_id' => $locked->warehouse_id,
                ];
            })->toArray();

            // Add items to stock
            $success = $this->stockService->addStock(
                $stockItems,
                MovementReason::PURCHASE,
                $locked
            );

            if ($success->isEmpty()) {
                throw new Exception('فشل في إضافة الأصناف إلى المخزون');
            }

            // Mark invoice as closed
            $locked->update([
                'closed_at' => now(),
                'business_date' => app(InventoryDailyAggregationService::class)->currentBusinessDate()->toDateString(),
            ]);

            DB::commit();

            $invoice->refresh();

            Log::info("Purchase invoice {$invoice->id} closed successfully");

            return true;

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Failed to close purchase invoice {$invoice->id}: ".$e->getMessage());
            throw $e;
        }
    }

    /**
     * Close a return purchase invoice and remove items from stock
     */
    public function closeReturnPurchaseInvoice(ReturnPurchaseInvoice $invoice): bool
    {
        if ($invoice->closed_at) {
            throw new Exception('هذه الفاتورة مغلقة بالفعل');
        }

        shouldDayBeOpen();

        try {
            DB::beginTransaction();

            $locked = ReturnPurchaseInvoice::whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->closed_at) {
                throw new Exception('هذه الفاتورة مغلقة بالفعل');
            }

            // Get all items for this invoice
            $items = $locked->items()->with('product')->orderBy('id')->lockForUpdate()->get();

            if ($items->isEmpty()) {
                throw new Exception('لا توجد أصناف في هذه الفاتورة');
            }

            // Prepare items for stock processing and validate availability
            $stockItems = $items->map(function ($item) use ($locked) {
                return [
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'warehouse_id' => $locked->warehouse_id,
                ];
            })->toArray();

            // Check stock availability
            $insufficientItems = $this->stockService->validateStockAvailability($stockItems);

            // if (!empty($insufficientItems)) {
            //     $errorMessage = 'مخزون غير كافي للمنتجات التالية: ';
            //     foreach ($insufficientItems as $item) {
            //         $errorMessage .= "\n- {$item['product_name']}: متوفر {$item['available_quantity']}, مطلوب {$item['required_quantity']}";
            //     }
            //     throw new \Exception($errorMessage);
            // }

            // Remove items from stock
            $success = $this->stockService->removeStock(
                $stockItems,
                MovementReason::PURCHASE_RETURN,
                $locked
            );

            if ($success->isEmpty()) {
                throw new Exception('فشل في خصم الأصناف من المخزون');
            }

            // Mark invoice as closed
            $locked->update([
                'closed_at' => now(),
                'business_date' => app(InventoryDailyAggregationService::class)->currentBusinessDate()->toDateString(),
            ]);

            DB::commit();

            $invoice->refresh();

            Log::info("Return purchase invoice {$invoice->id} closed successfully");

            return true;

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Failed to close return purchase invoice {$invoice->id}: ".$e->getMessage());
            throw $e;
        }
    }

    /**
     * Check if invoice can be edited
     */
    public function canEditInvoice($invoice): bool
    {
        return is_null($invoice->closed_at);
    }

    /**
     * Check if invoice is closed
     */
    public function isInvoiceClosed($invoice): bool
    {
        return ! is_null($invoice->closed_at);
    }
}
