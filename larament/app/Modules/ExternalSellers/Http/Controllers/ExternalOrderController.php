<?php

namespace App\Modules\ExternalSellers\Http\Controllers;

use App\Enums\OptionalFeature;
use App\Exceptions\OrderException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Modules\ExternalSellers\Services\ExternalOrderService;
use App\Services\FeatureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExternalOrderController extends Controller
{
    public function __construct(private readonly ExternalOrderService $service) {}

    public function save(Request $request, Order $order): RedirectResponse
    {
        try {
            app(FeatureService::class)->assertEnabled(OptionalFeature::OUTSOURCED_ORDERS);
        } catch (OrderException $exception) {
            return back()->withErrors(['error' => $exception->getMessage()]);
        }

        $data = $request->validate([
            'seller_id' => ['required', 'integer', 'exists:external_sellers,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('external_products', 'id')->where('external_seller_id', $request->input('seller_id'))->where('is_active', true)],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'min:0.001', 'max:999999999'],
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
            app(FeatureService::class)->assertEnabled(OptionalFeature::OUTSOURCED_ORDERS);
            $this->service->removeLine($order->id, $external, $item);

            return back()->with('success', 'تم حذف الصنف الخارجي');
        } catch (OrderException $exception) {
            return back()->withErrors(['error' => $exception->getMessage()]);
        }
    }
}
