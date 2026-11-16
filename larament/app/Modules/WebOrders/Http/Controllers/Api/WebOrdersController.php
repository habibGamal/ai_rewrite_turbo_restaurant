<?php

namespace App\Modules\WebOrders\Http\Controllers\Api;

use App\Enums\OrderBehavior;
use App\Http\Controllers\Controller;
use App\Modules\WebOrders\Exceptions\WebOrderConflictException;
use App\Modules\WebOrders\Services\WebApiService;
use App\Orders\OrderTypeCatalog;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WebOrdersController extends Controller
{
    protected WebApiService $webApiService;

    public function __construct(WebApiService $webApiService)
    {
        $this->webApiService = $webApiService;
    }

    /**
     * Check if we can accept new orders
     */
    public function canAcceptOrder(): JsonResponse
    {
        $canAccept = $this->webApiService->canAcceptOrder();

        return response()->json(['can_accept' => $canAccept]);
    }

    /**
     * Get current shift ID
     */
    public function getShiftId(): JsonResponse
    {
        $shiftId = $this->webApiService->getShiftId();

        return response()->json(['shift_id' => $shiftId]);
    }

    /**
     * Create a new web order
     */
    public function createOrder(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user' => 'required|array',
            'user.name' => 'required|string',
            'user.phone' => 'required|string',
            'user.area' => 'required|string',
            'user.address' => 'required|string',
            'order' => 'required|array',
            'order.type' => ['required', Rule::in(app(OrderTypeCatalog::class)->codesWithBehavior([
                OrderBehavior::WEB_DELIVERY,
                OrderBehavior::WEB_TAKEAWAY,
            ]))],
            'order.shiftId' => 'required|integer',
            'order.orderNumber' => 'required|string',
            'order.idempotencyKey' => 'nullable|string|max:100',
            'order.subTotal' => 'required|numeric',
            'order.tax' => 'required|numeric',
            'order.service' => 'required|numeric',
            'order.discount' => 'required|numeric',
            'order.total' => 'required|numeric',
            'order.note' => 'nullable|string',
            'order.webPreferences' => 'nullable|array',
            'order.webPreferences.payment_method' => 'nullable|in:cash,card,talabat_card',
            'order.webPreferences.transaction_id' => 'nullable|string',
            'order.items' => 'required|array',
            'order.items.*.quantity' => 'required|numeric|min:0.001',
            'order.items.*.notes' => 'nullable|string',
            'order.items.*.posRefObj' => 'required|array',
            'order.items.*.posRefObj.*.productRef' => 'required|string',
            'order.items.*.posRefObj.*.quantity' => 'required|numeric|min:0.001',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        try {
            $placed = $this->webApiService->placeOrder($request->all());

            return response()->json($placed->toResponse());
        } catch (WebOrderConflictException $e) {
            return response()->json([
                'message' => 'رقم الطلب مستخدم بالفعل لطلب مختلف',
                'error' => $e->getMessage(),
                'conflict' => true,
                'mismatches' => $e->mismatches,
                'existing_order' => [
                    'id' => $e->existing->id,
                    'order_number' => $e->existing->order_number,
                    'status' => $e->existing->status->value,
                ],
            ], 409);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'فشل في إنشاء الطلب',
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}
