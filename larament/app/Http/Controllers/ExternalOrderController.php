<?php

namespace App\Http\Controllers;

use App\Exceptions\OrderException;
use App\Models\Order;
use App\Services\Orders\ExternalOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ExternalOrderController extends Controller
{
    public function __construct(private readonly ExternalOrderService $service) {}

    public function save(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'seller_id' => ['required', 'integer', 'exists:external_sellers,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'min:0.001', 'max:999999999'],
            'items.*.price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
        ]);
        try {
            $this->service->save($order->id, (int) $data['seller_id'], $data['items']);

            return back()->with('success', 'تم حفظ الطلب الخارجي');
        } catch (OrderException $exception) {
            return back()->withErrors(['error' => $exception->getMessage()]);
        }
    }

    public function removeLine(Order $order, int $external, int $item): RedirectResponse
    {
        try {
            $this->service->removeLine($order->id, $external, $item);

            return back()->with('success', 'تم حذف الصنف الخارجي');
        } catch (OrderException $exception) {
            return back()->withErrors(['error' => $exception->getMessage()]);
        }
    }

    public function fulfill(Order $order, int $external): RedirectResponse
    {
        try {
            $this->service->fulfill($order->id, $external);

            return back()->with('success', 'تم تنفيذ الطلب الخارجي');
        } catch (OrderException $exception) {
            return back()->withErrors(['error' => $exception->getMessage()]);
        }
    }
}
