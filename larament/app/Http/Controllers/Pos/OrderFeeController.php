<?php

namespace App\Http\Controllers\Pos;

use App\Exceptions\OrderException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Orders\OrderFeeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * «رسوم إضافية» on the cashier's order screen. The service refuses while the
 * custom fees feature is off; the permissions are hidden then as well.
 */
class OrderFeeController extends Controller
{
    public function __construct(private readonly OrderFeeService $service) {}

    public function store(Request $request, Order $order): RedirectResponse
    {
        abort_unless($request->user()?->can('order.add_fees'), 403, 'غير مسموح لك بإضافة رسوم');

        $data = $request->validate([
            'fees' => ['required', 'array', 'min:1', 'max:20'],
            'fees.*.name' => ['required', 'string', 'max:'.OrderFeeService::MAX_NAME_LENGTH],
            'fees.*.amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999999'],
        ], [], [
            'fees.*.name' => 'اسم الرسوم',
            'fees.*.amount' => 'قيمة الرسوم',
        ]);

        try {
            $this->service->add($order->id, $data['fees']);

            return back()->with('success', 'تمت إضافة الرسوم');
        } catch (OrderException $exception) {
            return back()->withErrors(['error' => $exception->getMessage()]);
        }
    }

    public function destroy(Request $request, Order $order, int $fee): RedirectResponse
    {
        abort_unless($request->user()?->can('order.remove_fees'), 403, 'غير مسموح لك بحذف الرسوم');

        try {
            $this->service->remove($order->id, $fee);

            return back()->with('success', 'تم حذف الرسوم');
        } catch (OrderException $exception) {
            return back()->withErrors(['error' => $exception->getMessage()]);
        }
    }
}
