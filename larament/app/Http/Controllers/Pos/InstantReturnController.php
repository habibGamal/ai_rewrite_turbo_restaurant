<?php

namespace App\Http\Controllers\Pos;

use App\Exceptions\OrderException;
use App\Http\Controllers\Controller;
use App\Http\Requests\InstantSaleRequest;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\Instant\InstantReturnService;
use App\Services\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * F10 on the instant-sale screen: return lines of a completed sale, with an
 * optional exchange sold in the same transaction.
 */
class InstantReturnController extends Controller
{
    public function lookup(Request $request, InstantReturnService $returns, ShiftService $shifts): JsonResponse
    {
        $validated = $request->validate(['number' => ['required', 'string', 'max:32']]);

        $order = $returns->findCompleted($validated['number'], $this->shift($shifts));

        if (! $order instanceof Order) {
            return response()->json(['message' => 'لا يوجد طلب مكتمل بهذا الرقم'], 404);
        }

        return response()->json(['order' => $returns->payload($order)]);
    }

    public function store(Request $request, InstantReturnService $returns, ShiftService $shifts): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'reason' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'exchange' => ['nullable', 'array'],
            'exchange.client_uuid' => ['required_with:exchange', 'uuid'],
            'exchange.type' => ['required_with:exchange', 'string', 'exists:order_types,code'],
            ...InstantSaleRequest::itemRules('exchange.items', 'required_with:exchange'),
            ...InstantSaleRequest::tenderRules(),
            'refund_method' => ['nullable', 'in:cash,card'],
        ]);

        $user = $request->user();
        abort_unless($user instanceof User && $user->can('create_order_returns'), 403, 'غير مسموح لك بإرجاع الطلبات');

        $exchange = null;
        if (! empty($validated['exchange']['items'])) {
            abort_unless($user->canSeeTab('type:'.$validated['exchange']['type']), 403);
            $exchange = [
                'client_uuid' => $validated['exchange']['client_uuid'],
                'type' => $validated['exchange']['type'],
                'items' => InstantSaleRequest::lines($validated['exchange']['items']),
            ];
        }

        $order = Order::query()->findOrFail($validated['order_id']);

        $result = $returns->process(
            order: $order,
            lines: array_map(fn (array $line): array => [
                'order_item_id' => (int) $line['order_item_id'],
                'quantity' => (float) $line['quantity'],
            ], $validated['items']),
            reason: (string) ($validated['reason'] ?? ''),
            shiftId: $this->shift($shifts),
            userId: $user->id,
            tenders: InstantSaleRequest::tenders($validated['tenders'] ?? []),
            exchange: $exchange,
            refundMethod: $validated['refund_method'] ?? 'cash',
        );

        return response()->json([
            'return' => ['id' => $result['return']->id, 'total_refund' => $result['refund']],
            'exchange_order' => $result['exchange']?->toArray(),
            'net' => $result['net'],
        ]);
    }

    private function shift(ShiftService $shifts): int
    {
        $shift = $shifts->getCurrentShift();

        if ($shift === null) {
            throw new OrderException('لا يوجد شيفت مفتوح');
        }

        return $shift->id;
    }
}
