<?php

namespace App\Modules\ExternalSellers;

use App\Enums\OrderStatus;
use App\Models\ExternalOrder;
use App\Models\ExternalSeller;
use App\Modules\ExternalSellers\Services\ExternalSellerSettlementService;
use Illuminate\Support\Facades\Schema;

/**
 * Refuses to switch External sellers off while an open order still carries
 * an external order or a seller is still owed money.
 */
final class SellersDisableBlocker
{
    public function __construct(
        private readonly ExternalSellerSettlementService $settlementService,
    ) {}

    /**
     * @return list<string>
     */
    public function __invoke(): array
    {
        $blockers = [];

        if (Schema::hasTable('external_orders') && Schema::hasTable('orders')) {
            $openExternalOrdersCount = ExternalOrder::query()
                ->where('status', '!=', 'cancelled')
                ->whereHas('host', fn ($query) => $query->whereIn('status', [
                    OrderStatus::PENDING,
                    OrderStatus::PROCESSING,
                    OrderStatus::OUT_FOR_DELIVERY,
                ]))
                ->lockForUpdate()
                ->count();

            if ($openExternalOrdersCount > 0) {
                $blockers[] = "لا يمكن إيقاف ميزة الطلبات الخارجية لوجود {$openExternalOrdersCount} طلب خارجي قيد التشغيل على طلبات مفتوحة.";
            }
        }

        if (Schema::hasTable('external_sellers')) {
            $sellers = ExternalSeller::query()->lockForUpdate()->get();
            $unsettledSellers = $sellers->filter(
                fn (ExternalSeller $seller): bool => abs($this->settlementService->payable($seller)) > 0.0001
            );

            if ($unsettledSellers->isNotEmpty()) {
                $names = $unsettledSellers->pluck('name')->implode('، ');
                $blockers[] = "لا يمكن إيقاف ميزة الطلبات الخارجية لوجود مستحقات غير مسواة للمتاجر المتعاونة ({$names}).";
            }
        }

        return $blockers;
    }
}
