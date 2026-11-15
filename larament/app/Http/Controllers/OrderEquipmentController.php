<?php

namespace App\Http\Controllers;

use App\Enums\OptionalFeature;
use App\Exceptions\OrderException;
use App\Models\Order;
use App\Services\FeatureService;
use App\Services\Orders\OrderEquipmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The cashier's «إضافة معدات» modal: set equipment quantities on an open order,
 * or remove one line.
 */
class OrderEquipmentController extends Controller
{
    public function __construct(private readonly OrderEquipmentService $service) {}

    public function save(Request $request, Order $order): RedirectResponse
    {
        if (! app(FeatureService::class)->equipmentEnabled()) {
            return back()->withErrors(['error' => OptionalFeature::EQUIPMENT->disabledErrorMessage()]);
        }

        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.equipment_id' => ['required', 'integer', 'distinct', 'exists:equipment,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:0', 'max:9999'],
        ]);

        $quantities = [];
        foreach ($data['lines'] as $line) {
            $quantities[(int) $line['equipment_id']] = (int) $line['quantity'];
        }

        try {
            $this->service->sync($order->id, $quantities);

            return back()->with('success', 'تم حفظ المعدات');
        } catch (OrderException $exception) {
            return back()->withErrors(['error' => $exception->getMessage()]);
        }
    }

    public function remove(Order $order, int $line): RedirectResponse
    {
        if (! app(FeatureService::class)->equipmentEnabled()) {
            return back()->withErrors(['error' => OptionalFeature::EQUIPMENT->disabledErrorMessage()]);
        }

        try {
            $this->service->removeLine($order->id, $line);

            return back()->with('success', 'تم حذف المعدة');
        } catch (OrderException $exception) {
            return back()->withErrors(['error' => $exception->getMessage()]);
        }
    }
}
