<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Category;
use App\Models\Region;
use App\Services\WebApiService;
use App\Services\Orders\OrderService;
use App\Enums\OrderType;
use App\Enums\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class WebOrderController extends Controller
{
    protected WebApiService $webApiService;
    protected OrderService $orderService;

    public function __construct(WebApiService $webApiService, OrderService $orderService)
    {
        $this->webApiService = $webApiService;
        $this->orderService = $orderService;
    }

    /**
     * Show the web order management page
     */
    public function manage(Order $order): Response
    {
        $order->load(['customer', 'driver', 'items.product', 'payments']);

        $categories = Category::with(['products' => function ($query) {
            $query->where('type', 'manufactured');
        }])->get();

        // Get all drivers for the dropdown
        $drivers = Driver::orderBy('name')->get();

        // Get all regions for the dropdown
        $regions = Region::orderBy('name')->get();

        return Inertia::render('Orders/ManageWebOrder', [
            'order' => $order,
            'categories' => $categories,
            'drivers' => $drivers,
            'regions' => $regions,
        ]);
    }

    /**
     * Accept a web order
     */
    public function acceptOrder(Order $order)
    {
        try {
            $this->webApiService->acceptOrder($order->id);

            return Redirect::back()->with('success', 'تم قبول الطلب بنجاح');
        } catch (\Exception $e) {
            return Redirect::back()->with('error', 'فشل في قبول الطلب: ' . $e->getMessage());
        }
    }

    /**
     * Reject a web order
     */
    public function rejectOrder(Order $order)
    {
        try {
            return DB::transaction(function () use ($order) {
                // Use OrderService to cancel the order locally
                $this->orderService->cancelOrder($order->id, 'تم إلغاء الطلب من واجهة الويب');

                // Sync with external API
                $this->webApiService->rejectOrder($order->id);

                return Redirect::back()->with('success', 'تم إلغاء الطلب');
            });
        } catch (\Exception $e) {
            return Redirect::back()->with('error', 'فشل في إلغاء الطلب: ' . $e->getMessage());
        }
    }

    /**
     * Cancel a web order
     */
    public function cancelOrder(Order $order)
    {
        try {
            return DB::transaction(function () use ($order) {
                // Use OrderService to cancel the order locally
                $this->orderService->cancelOrder($order->id, 'تم إلغاء الطلب من واجهة الويب');

                // Sync with external API
                $this->webApiService->rejectOrder($order->id);

                return Redirect::back()->with('success', 'تم إلغاء الطلب');
            });
        } catch (\Exception $e) {
            return Redirect::back()->with('error', 'فشل في إلغاء الطلب: ' . $e->getMessage());
        }
    }

    /**
     * Set order as out for delivery
     */
    public function outForDelivery(Order $order)
    {
        try {
            $this->webApiService->outForDelivery($order->id);

            return Redirect::back()->with('success', 'تم تحديد الطلب كخارج للتوصيل');
        } catch (\Exception $e) {
            return Redirect::back()->with('error', 'فشل في تحديث حالة الطلب: ' . $e->getMessage());
        }
    }

    /**
     * Complete a web order
     */
    public function completeOrder(Order $order, Request $request)
    {
        // Validate payment data based on payment mode
        $request->validate([
            'cash' => 'sometimes|numeric|min:0',
            'card' => 'sometimes|numeric|min:0',
            'talabat_card' => 'sometimes|numeric|min:0',
            'paymentMethod' => 'sometimes|in:cash,card,talabat_card',
            'paid' => 'sometimes|numeric|min:0',
            'print' => 'sometimes|boolean',
        ]);

        try {
            return DB::transaction(function () use ($order, $request) {
                // Prepare payments data for OrderService
                $paymentsData = [];
                $shouldPrint = $request->boolean('print');

                // Handle single payment mode
                if ($request->has('paymentMethod') && $request->has('paid')) {
                    $paymentsData[$request->get('paymentMethod')] = $request->get('paid');
                } else {
                    // Handle multi-payment mode
                    $paymentMethods = ['cash', 'card', 'talabat_card'];

                    foreach ($paymentMethods as $method) {
                        $amount = $request->get($method, 0);
                        if ($amount > 0) {
                            $paymentsData[$method] = $amount;
                        }
                    }
                }

                // Use OrderService to complete the order locally
                $completedOrder = $this->orderService->completeOrder($order->id, $paymentsData, $shouldPrint);
                // Sync with external API
                $this->webApiService->completeOrder($order->id);
                $message = $shouldPrint ? 'تم إكمال الطلب وطباعة الفاتورة' : 'تم إكمال الطلب بنجاح';

                return Redirect::back()->with('success', $message);
            });
        } catch (\Exception $e) {
            return Redirect::back()->with('error', 'فشل في إكمال الطلب: ' . $e->getMessage());
        }
    }

    /**
     * Apply discount to order
     */
    public function applyDiscount(Order $order, Request $request)
    {
        $request->validate([
            'discount' => 'required|numeric|min:0',
            'discountType' => 'required|in:percent,value',
        ]);

        try {
            $this->webApiService->applyDiscount(
                $order->id,
                $request->get('discount'),
                $request->get('discountType')
            );

            return Redirect::back()->with('success', 'تم تطبيق الخصم');
        } catch (\Exception $e) {
            return Redirect::back()->with('error', 'فشل في تطبيق الخصم: ' . $e->getMessage());
        }
    }

    /**
     * Save order (only update item notes for web orders)
     */
    public function saveOrder(Order $order, Request $request)
    {
        $request->validate([
            'items' => 'required|array',
            'items.*.product_id' => 'required|integer',
            'items.*.notes' => 'nullable|string',
        ]);

        try {
            $order->load('items');

            foreach ($request->get('items') as $itemData) {
                if (empty($itemData['notes'])) {
                    continue;
                }

                $orderItem = $order->items->firstWhere('product_id', $itemData['product_id']);
                if ($orderItem) {
                    $orderItem->notes = $itemData['notes'];
                    $orderItem->save();
                }
            }

            return Redirect::back()->with('success', 'تم حفظ الطلب');
        } catch (\Exception $e) {
            return Redirect::back()->with('error', 'فشل في حفظ الطلب: ' . $e->getMessage());
        }
    }
}
