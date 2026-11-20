<?php

namespace App\Services\Orders;

use App\Enums\SettingKey;
use App\Exceptions\OrderException;
use App\Models\Order;
use App\Models\User;
use App\Services\SettingsService;
use Carbon\CarbonInterface;

/**
 * Who may return what: the optional «أقصى عدد أيام للمرتجع» limit and the
 * permissions that lift it or allow returning a line as damaged.
 */
class ReturnRules
{
    public const PERMISSION = 'order.return';

    public const WITHOUT_RESTOCK = 'order.return_without_restock';

    public const ANY_AGE = 'order.return_any_age';

    public function __construct(private readonly SettingsService $settings) {}

    /** 0 = no limit. */
    public function maxDays(): int
    {
        $value = $this->settings->get(SettingKey::RETURNS_MAX_DAYS->value, '0');

        return max(0, (int) filter_var($value, FILTER_VALIDATE_INT, ['options' => ['default' => 0]]));
    }

    public function ageInDays(Order $order, ?CarbonInterface $now = null): int
    {
        $now ??= now();
        $created = $order->created_at ?? $now;

        return (int) $created->copy()->startOfDay()->diffInDays($now->copy()->startOfDay());
    }

    public function isTooOld(Order $order, ?CarbonInterface $now = null): bool
    {
        $max = $this->maxDays();

        return $max > 0 && $this->ageInDays($order, $now) > $max;
    }

    public function canReturnAnyAge(?User $user): bool
    {
        return $user !== null && $user->can(self::ANY_AGE);
    }

    public function canSkipRestock(?User $user): bool
    {
        return $user !== null && $user->can(self::WITHOUT_RESTOCK);
    }

    /**
     * @throws OrderException
     */
    public function assertAgeAllowed(Order $order, ?User $user): void
    {
        if ($this->isTooOld($order) && ! $this->canReturnAnyAge($user)) {
            throw new OrderException(sprintf(
                'لا يمكن إرجاع طلب أقدم من %d يوم (عمر الطلب %d يوم)',
                $this->maxDays(),
                $this->ageInDays($order),
            ));
        }
    }
}
