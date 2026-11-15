<?php

namespace App\Modules\Delivery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Order;
use App\Services\Orders\OrderService;
use App\Services\ShiftLoggingService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Cashier-screen endpoints for drivers: create, look up and link a driver to
 * a delivery order. Moved out of OrderController in Phase 4b.
 */
class DriverPosController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly ShiftLoggingService $loggingService,
    ) {}

    /**
     * Update driver information
     */
    public function updateDriver(Request $request, Order $order)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
        ]);

        try {
            DB::beginTransaction();

            if ($order->driver) {
                $order->driver->update($validated);
                $driver = $order->driver;
                $actionType = 'update';
            } else {
                $driver = Driver::create($validated);
                $this->orderService->linkDriver($order->id, $driver->id);
                $actionType = 'create';
            }

            // Log driver action
            $this->loggingService->logDriverAction($actionType, $validated, $order->id);

            DB::commit();

            return back()->with('success', 'تم حفظ بيانات السائق بنجاح');
        } catch (Exception $e) {
            DB::rollBack();

            $this->loggingService->logAction('فشل في حفظ بيانات السائق', [
                'order_id' => $order->id,
                'driver_data' => $validated,
                'error' => $e->getMessage(),
            ], 'error');

            return back()->withErrors(['error' => 'حدث خطأ أثناء حفظ بيانات السائق']);
        }
    }

    /**
     * Quick create driver
     */
    public function quickDriver(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
        ]);

        try {
            $driver = Driver::create($validated);

            // Log driver creation
            $this->loggingService->logDriverAction('create', [
                'name' => $driver->name,
                'phone' => $driver->phone,
            ]);

            return response()->json($driver);
        } catch (Exception $e) {
            $this->loggingService->logAction('فشل في إنشاء سائق جديد', [
                'driver_data' => $validated,
                'error' => $e->getMessage(),
            ], 'error');

            return response()->json(['error' => 'حدث خطأ أثناء إنشاء السائق'], 500);
        }
    }

    /**
     * Fetch driver info by phone
     */
    public function fetchDriverInfo(Request $request)
    {
        $validated = $request->validate([
            'phone' => 'required|string|max:20',
        ]);

        try {
            $driver = Driver::where('phone', $validated['phone'])->first();

            if (! $driver) {
                return response()->json(['error' => 'لم يتم العثور على السائق'], 404);
            }

            return response()->json($driver);
        } catch (Exception $e) {
            return response()->json(['error' => 'حدث خطأ أثناء البحث عن السائق'], 500);
        }
    }

    /**
     * Link driver to order
     */
    public function linkDriver(Request $request, Order $order)
    {
        $validated = $request->validate([
            'driverId' => 'required|integer|exists:drivers,id',
        ]);

        try {
            $driver = Driver::find($validated['driverId']);
            $this->orderService->linkDriver($order->id, $validated['driverId']);

            // Log driver linking
            $this->loggingService->logDriverAction('link', [
                'name' => $driver->name,
                'phone' => $driver->phone,
            ], $order->id);

            // Return in Inertia page props format
            $updatedOrder = $this->orderService->getOrderDetails($order->id);

            return back()->with([
                'success' => 'تم ربط السائق بالطلب بنجاح',
                'order' => $updatedOrder,
            ]);
        } catch (Exception $e) {
            $this->loggingService->logAction('فشل في ربط السائق بالطلب', [
                'order_id' => $order->id,
                'driver_id' => $validated['driverId'],
                'error' => $e->getMessage(),
            ], 'error');

            return back()->withErrors(['error' => 'حدث خطأ أثناء ربط السائق']);
        }
    }
}
