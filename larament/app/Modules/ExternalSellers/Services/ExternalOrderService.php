<?php

namespace App\Modules\ExternalSellers\Services;

use App\Enums\OptionalFeature;
use App\Exceptions\OrderException;
use App\Models\ExternalOrder;
use App\Models\ExternalSeller;
use App\Models\Order;
use App\Services\FeatureService;
use App\Sync\DocumentType;
use App\Sync\Outbox;
use Closure;
use Illuminate\Support\Facades\DB;

class ExternalOrderService
{
    public function save(int $orderId, int $sellerId, array $lines): ExternalOrder
    {
        return DB::transaction(function () use ($orderId, $sellerId, $lines) {
            app(FeatureService::class)->lockAndAssertEnabled(OptionalFeature::OUTSOURCED_ORDERS);
            $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
            if (! $order->status->canBeModified() || $order->typeRow()->allowsCredit()) {
                throw new OrderException('لا يمكن إضافة طلب خارجي لهذا الطلب');
            }

            $seller = ExternalSeller::whereKey($sellerId)->where('is_active', true)->firstOrFail();
            $products = $seller->products()->where('is_active', true)->whereIn('id', array_column($lines, 'product_id'))->lockForUpdate()->get()->keyBy('id');
            $totalAmount = 0.0;
            foreach ($lines as $line) {
                $product = $products->get($line['product_id']);
                if (! $product || (float) ($line['quantity'] ?? 0) <= 0) {
                    throw new OrderException('بيانات الصنف الخارجي غير صالحة');
                }
                $totalAmount += round((float) $line['quantity'] * (float) $product->price, 2);
            }
            if ($lines === [] || $totalAmount > 9999999999.99) {
                throw new OrderException('إجمالي الطلب الخارجي غير صالح');
            }
            $external = ExternalOrder::firstOrNew(['host_order_id' => $order->id, 'external_seller_id' => $seller->id]);
            $external->seller_name = $external->exists ? $external->seller_name : $seller->name;
            $external->status = 'pending';
            $external->total = 0;
            $external->save();
            $external->items()->delete();
            foreach ($lines as $line) {
                $product = $products->get($line['product_id']);
                $total = round((float) $line['quantity'] * (float) $product->price, 2);
                $external->items()->create(['external_product_id' => $product->id, 'name' => $product->name, 'quantity' => $line['quantity'], 'price' => $product->price, 'total' => $total]);
                $external->total += $total;
            }
            $external->save();
            Outbox::record(DocumentType::Order, (string) $order->getAttribute('uuid'));

            return $external->fresh(['items']);
        });
    }

    public function removeLine(int $orderId, int $externalId, int $itemId): void
    {
        $this->withLockedOrder($orderId, function (Order $order) use ($externalId, $itemId): void {
            $external = $order->externalOrders()->whereKey($externalId)->firstOrFail();
            $external->items()->whereKey($itemId)->firstOrFail()->delete();
            $external->update(['total' => $external->items()->sum('total')]);
        });
    }

    public function fulfill(int $orderId, int $externalId): void
    {
        $this->withLockedOrder($orderId, function (Order $order) use ($externalId): void {
            $order->externalOrders()->whereKey($externalId)->firstOrFail()->update(['status' => 'fulfilled']);
        });
    }

    private function withLockedOrder(int $orderId, Closure $callback): void
    {
        DB::transaction(function () use ($orderId, $callback): void {
            app(FeatureService::class)->lockAndAssertEnabled(OptionalFeature::OUTSOURCED_ORDERS);
            $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
            if (! $order->status->canBeModified() || $order->typeRow()->allowsCredit()) {
                throw new OrderException('لا يمكن تعديل طلب خارجي لهذا الطلب');
            }
            $callback($order);
            Outbox::record(DocumentType::Order, (string) $order->getAttribute('uuid'));
        });
    }
}
