<?php

namespace App\Modules\Restaurant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\TableType;
use App\Services\PrintService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Cashier-screen endpoints that only a restaurant uses: table types for the
 * table picker and sending items to the kitchen printers.
 */
class RestaurantPosController extends Controller
{
    public function __construct(
        private readonly PrintService $printService,
    ) {}

    /**
     * Print kitchen order with multiple printers
     */
    public function printInKitchen(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'orderId' => 'required|integer|exists:orders,id',
            'type' => 'nullable|string|in:normal,changes',
            'items' => 'nullable|array',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.name' => 'required|string',
            'items.*.quantity' => 'nullable|numeric',
            'items.*.old_quantity' => 'nullable|numeric',
            'items.*.new_quantity' => 'nullable|numeric',
            'items.*.delta' => 'nullable|numeric',
            'items.*.notes' => 'nullable|string|max:500',
        ]);

        try {
            $type = $validated['type'] ?? 'normal';

            if ($type === 'changes') {
                $this->printService->printKitchenChangesReceipt(
                    $validated['orderId'],
                    $validated['items'] ?? []
                );
            } else {
                $this->printService->printKitchenReceipt(
                    $validated['orderId'],
                    $validated['items'] ?? []
                );
            }

            return back()->with('success', 'تم إرسال الطلب للمطبخ بنجاح');
        } catch (Exception $e) {
            return back()->withErrors(['error' => 'حدث خطأ أثناء طباعة طلب المطبخ: '.$e->getMessage()]);
        }
    }

    /**
     * Get table types
     */
    public function tableTypes(): JsonResponse
    {
        try {
            $tableTypes = TableType::orderBy('name')->get();

            // If no table types exist, return default option
            if ($tableTypes->isEmpty()) {
                $tableTypes = collect([
                    (object) [
                        'id' => null,
                        'name' => 'صالة',
                        'created_at' => null,
                        'updated_at' => null,
                    ],
                ]);
            }

            return response()->json($tableTypes);
        } catch (Exception $e) {
            return response()->json(['error' => 'حدث خطأ أثناء جلب أنواع الطاولات'], 500);
        }
    }
}
