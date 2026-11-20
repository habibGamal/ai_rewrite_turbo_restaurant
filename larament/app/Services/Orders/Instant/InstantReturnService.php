<?php

namespace App\Services\Orders\Instant;

use App\DTOs\Orders\InstantSaleData;
use App\Enums\OrderStatus;
use App\Exceptions\OrderException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\OrderReturnItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\OrderReturnService;
use App\Services\Orders\ReturnRefundCalculator;
use App\Services\Orders\ReturnRules;
use Illuminate\Support\Facades\DB;

/**
 * F10 on the instant-sale screen: return lines of a completed sale and,
 * for an exchange, sell the new lines as a second instant sale in the same
 * transaction. The customer settles only the difference: the refund pays
 * the exchange first, and the rest is paid by the customer or handed back.
 */
class InstantReturnService
{
    public function __construct(
        private readonly OrderReturnService $returns,
        private readonly InstantSaleService $sales,
        private readonly ReturnRules $rules,
        private readonly ReturnRefundCalculator $refunds,
    ) {}

    /** How many receipts of other shifts the lookup lists for one typed number. */
    public const CANDIDATES_LIMIT = 10;

    /**
     * Turn what the cashier typed or scanned into a reference. A receipt's
     * barcode encodes "#<order id>", which only matches that order; bare
     * digits are a receipt number (per shift) or an order id.
     *
     * @return array{value: int, exact: bool}|null
     */
    public static function parseReference(string $input): ?array
    {
        $input = strtr(trim($input), [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $input = (string) preg_replace('/\s+/u', '', $input);

        $exact = str_starts_with($input, '#');
        $digits = ltrim($input, '#');

        if ($digits === '' || ! ctype_digit($digits) || strlen($digits) > 12) {
            return null;
        }

        return ['value' => (int) $digits, 'exact' => $exact];
    }

    /**
     * Completed orders the typed reference can mean, most likely first: the
     * current shift's receipt, then the order with that id, then receipts of
     * the same number in earlier shifts (newest first, within the return window).
     *
     * @return list<Order>
     */
    public function candidates(string $input, int $currentShiftId): array
    {
        $reference = self::parseReference($input);
        if ($reference === null) {
            return [];
        }

        $base = fn () => Order::query()
            ->where('status', OrderStatus::COMPLETED)
            ->with(['user:id,name,email', 'shift:id,start_at,business_date']);

        if ($reference['exact']) {
            $order = $base()->whereKey($reference['value'])->first();

            return $order instanceof Order ? [$order] : [];
        }

        $found = collect();

        $current = $base()->where('shift_id', $currentShiftId)->where('order_number', $reference['value'])->first();
        if ($current instanceof Order) {
            $found->push($current);
        }

        $byId = $base()->whereKey($reference['value'])->first();
        if ($byId instanceof Order) {
            $found->push($byId);
        }

        $maxDays = $this->rules->maxDays();
        $older = $base()
            ->where('order_number', $reference['value'])
            ->where('shift_id', '!=', $currentShiftId)
            ->when($maxDays > 0, fn ($query) => $query->where('created_at', '>=', now()->subDays($maxDays)->startOfDay()))
            ->latest('id')
            ->limit(self::CANDIDATES_LIMIT)
            ->get();

        return $found->concat($older)->unique('id')->values()->all();
    }

    /**
     * A short line per candidate so the cashier can pick the right receipt.
     *
     * @return array<string, mixed>
     */
    public function candidatePayload(Order $order, int $currentShiftId): array
    {
        $user = $order->user;

        return [
            'id' => $order->id,
            'order_number' => (int) $order->order_number,
            'reference' => '#'.$order->id,
            'created_at' => $order->created_at,
            'cashier' => $user instanceof User ? ($user->name ?: $user->email) : null,
            'total' => (float) $order->total,
            'current_shift' => (int) $order->shift_id === $currentShiftId,
            'too_old' => $this->rules->isTooOld($order),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Order $order): array
    {
        $order->load('items.product');
        $returned = OrderReturnItem::query()
            ->whereIn('order_item_id', $order->items->modelKeys())
            ->selectRaw('order_item_id, SUM(quantity) as quantity, SUM(refund_amount) as refunded')
            ->groupBy('order_item_id')
            ->get()
            ->keyBy('order_item_id');
        $lines = $this->refunds->lines($order);

        $items = [];
        foreach ($order->items as $item) {
            if (! $item instanceof OrderItem) {
                continue;
            }

            $product = $item->product;
            $line = $lines[$item->id];
            $items[] = [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'name' => $product instanceof Product ? $product->name : '',
                'quantity' => (float) $item->quantity,
                'price' => (float) $item->price,
                'returnable_quantity' => max(0.0, (float) $item->quantity - (float) ($returned[$item->id]->quantity ?? 0)),
                'unit_refund' => $line['unit_refund'],
                // The client prices a partial return as refundable × qty / quantity
                // and the last units as refundable − refunded, like the server.
                'refundable' => $line['refundable'],
                'refunded' => round((float) ($returned[$item->id]->refunded ?? 0), 2),
                'discounted' => $line['item_discount'] + $line['order_discount'] > 0.004,
                'explanation' => ReturnRefundCalculator::explain($line),
            ];
        }

        return [
            'id' => $order->id,
            'order_number' => (int) $order->order_number,
            'reference' => '#'.$order->id,
            'created_at' => $order->created_at,
            'age_days' => $this->rules->ageInDays($order),
            'too_old' => $this->rules->isTooOld($order),
            'total' => (float) $order->total,
            'items' => $items,
        ];
    }

    /**
     * @param  list<array{order_item_id: int, quantity: float, restocked?: bool}>  $lines
     * @param  array<string, float>  $tenders  what the customer hands over for the difference
     * @param  array{client_uuid: string, type: string, items: list<array<string, mixed>>}|null  $exchange
     * @return array{return: OrderReturn, exchange: ?InstantSaleResult, refund: float, net: float}
     */
    public function process(
        Order $order,
        array $lines,
        string $reason,
        int $shiftId,
        int $userId,
        array $tenders = [],
        ?array $exchange = null,
        string $refundMethod = 'cash',
    ): array {
        if ($order->status !== OrderStatus::COMPLETED) {
            throw new OrderException('لا يمكن إرجاع طلب غير مكتمل');
        }

        if ($lines === []) {
            throw new OrderException('يجب تحديد صنف واحد على الأقل للإرجاع');
        }

        return DB::transaction(function () use ($order, $lines, $reason, $shiftId, $userId, $tenders, $exchange, $refundMethod): array {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $order->load('items');

            $earlier = OrderReturnItem::query()->whereIn('order_item_id', $order->items->modelKeys())->get();

            $returnItems = [];
            $taken = [];
            foreach ($lines as $line) {
                $item = $order->items->firstWhere('id', (int) $line['order_item_id']);
                if (! $item instanceof OrderItem) {
                    throw new OrderException('صنف الطلب غير موجود');
                }

                $quantity = (float) $line['quantity'];
                $taken[$item->id] ??= [
                    'quantity' => (float) $earlier->where('order_item_id', $item->id)->sum('quantity'),
                    'refund' => (float) $earlier->where('order_item_id', $item->id)->sum('refund_amount'),
                ];
                $refund = $this->refunds->refundFor($order, $item->id, $quantity, $taken[$item->id]['quantity'], $taken[$item->id]['refund']);
                $taken[$item->id]['quantity'] += $quantity;
                $taken[$item->id]['refund'] += $refund;

                $returnItems[] = [
                    'order_item_id' => $item->id,
                    'quantity' => $quantity,
                    'refund_amount' => $refund,
                    'restocked' => (bool) ($line['restocked'] ?? true),
                ];
            }

            $refund = round((float) array_sum(array_column($returnItems, 'refund_amount')), 2);

            try {
                $orderReturn = $this->returns->processReturn(
                    order: $order,
                    returnItems: $returnItems,
                    reason: $reason !== '' ? $reason : ($exchange !== null ? 'استبدال' : 'مرتجع'),
                    refundDistribution: $refund > 0 ? [['method' => $refundMethod, 'amount' => $refund]] : [],
                    shiftId: $shiftId,
                );
            } catch (OrderException $e) {
                throw $e;
            } catch (\Exception $e) {
                throw new OrderException($e->getMessage());
            }

            $sale = null;
            $net = -$refund;

            if ($exchange !== null && $exchange['items'] !== []) {
                // The refund pays the new lines first; the customer's tenders cover the rest.
                $sale = $this->sales->sellInTransaction(new InstantSaleData(
                    clientUuid: $exchange['client_uuid'],
                    typeCode: $exchange['type'],
                    shiftId: $shiftId,
                    userId: $userId,
                    items: $exchange['items'],
                    tenders: $tenders,
                    customerId: $order->customer_id,
                    credit: $refund,
                    creditMethod: $refundMethod,
                ));

                $net = round((float) $sale->order->total - $refund, 2);
                $orderReturn->update(['exchange_order_id' => $sale->order->id]);
            }

            return ['return' => $orderReturn, 'exchange' => $sale, 'refund' => $refund, 'net' => $net];
        });
    }
}
