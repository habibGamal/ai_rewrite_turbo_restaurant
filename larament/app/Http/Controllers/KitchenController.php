<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\UserRole;
use App\Services\Orders\OrderService;
use App\Services\ShiftService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class KitchenController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private OrderService $orderService,
        private ShiftService $shiftService
    ) {}

    /**
     * Display the kitchen screen with current shift orders.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if (! $user->canAccessKitchen()) {
            abort(403, 'غير مسموح بالوصول لشاشة المطبخ');
        }

        $currentShift = $this->shiftService->getCurrentShift();

        if (! $currentShift) {
            return Inertia::render('Kitchen/Index', [
                'orders' => [],
                'hasActiveShift' => false,
                'orderTypes' => $this->getOrderTypes(),
                'orderStatuses' => $this->getOrderStatuses(),
            ]);
        }

        $orders = $this->orderService->getShiftOrders($currentShift->id);

        // Load item changes for kitchen changelog display
        $orders->load('itemChanges');

        // Pass permission info so frontend knows what the user can do
        $canManageOrders = $user->canManageOrders();

        return Inertia::render('Kitchen/Index', [
            'orders' => $orders,
            'hasActiveShift' => true,
            'canManageOrders' => $canManageOrders,
            'orderTypes' => $this->getOrderTypes(),
            'orderStatuses' => $this->getOrderStatuses(),
        ]);
    }

    /**
     * Get order types for filter dropdown.
     */
    private function getOrderTypes(): array
    {
        return collect(OrderType::cases())->map(fn (OrderType $type) => [
            'value' => $type->value,
            'label' => $type->label(),
        ])->toArray();
    }

    /**
     * Get order statuses for filter dropdown.
     */
    private function getOrderStatuses(): array
    {
        return collect(OrderStatus::cases())->map(fn (OrderStatus $status) => [
            'value' => $status->value,
            'label' => $status->label(),
        ])->toArray();
    }
}
