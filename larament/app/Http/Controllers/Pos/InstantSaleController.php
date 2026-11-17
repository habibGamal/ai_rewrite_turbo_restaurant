<?php

namespace App\Http\Controllers\Pos;

use App\DTOs\Orders\InstantSaleData;
use App\Exceptions\OrderException;
use App\Http\Controllers\Controller;
use App\Http\Requests\InstantSaleRequest;
use App\Models\User;
use App\Services\Orders\Instant\InstantSaleService;
use App\Services\PrintService;
use App\Services\ShiftLoggingService;
use App\Services\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class InstantSaleController extends Controller
{
    public function store(
        InstantSaleRequest $request,
        InstantSaleService $sales,
        ShiftService $shifts,
        PrintService $printer,
        ShiftLoggingService $logging,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $type = (string) $request->string('type');
        abort_unless($user->canSeeTab('type:'.$type), 403, 'غير مسموح لك بهذا النوع من الطلبات');
        abort_unless($user->can('order.complete'), 403, 'غير مسموح لك بإنهاء الطلبات');

        $discount = $request->input('discount');
        if (is_array($discount) && (float) ($discount['value'] ?? 0) > 0) {
            abort_unless($user->can('order.apply_discount'), 403, 'غير مسموح لك بتطبيق الخصم');
        }

        $shift = $shifts->getCurrentShift();
        if ($shift === null) {
            throw new OrderException('لا يوجد شيفت مفتوح');
        }

        $items = InstantSaleRequest::lines((array) $request->input('items'));
        $tenders = InstantSaleRequest::tenders($request->input('tenders'));

        $result = $sales->sell(new InstantSaleData(
            clientUuid: (string) $request->string('client_uuid'),
            typeCode: $type,
            shiftId: $shift->id,
            userId: $user->id,
            items: $items,
            tenders: $tenders,
            discount: is_array($discount) ? ['value' => (float) $discount['value'], 'type' => (string) $discount['type']] : null,
            customerId: $request->integer('customer_id') ?: null,
            heldSaleId: $request->integer('held_sale_id') ?: null,
            print: $request->boolean('print'),
            orderNotes: trim((string) $request->input('order_notes', '')) ?: null,
        ));

        if (! $result->duplicate) {
            $logging->logOrderCompletion($result->order->id, $tenders, (float) $result->order->total);

            if ($request->boolean('print')) {
                try {
                    $printer->printOrderReceipt($result->order->load(['items.product', 'user', 'customer', 'payments']));
                } catch (Throwable $e) {
                    // The sale is committed; the cashier reprints from the last-sale button.
                    Log::warning('Instant sale receipt failed to print', ['order_id' => $result->order->id, 'error' => $e->getMessage()]);
                }
            }
        }

        return response()->json([
            'order' => $result->toArray(),
            'duplicate' => $result->duplicate,
        ]);
    }
}
