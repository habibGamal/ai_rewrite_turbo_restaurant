<?php

namespace App\Models;

use App\Enums\GuestsMode;
use App\Enums\OrderBehavior;
use App\Observers\OrderTypeRowObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(OrderTypeRowObserver::class)]
class OrderTypeRow extends Model
{
    protected $table = 'order_types';

    protected $fillable = [
        'code',
        'label',
        'color',
        'icon',
        'is_active',
        'is_system',
        'behavior',
        'allow_partial_payment',
        'allow_pay_later',
        'require_customer_for_credit',
        'guests_mode',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'behavior' => OrderBehavior::class,
        'allow_partial_payment' => 'boolean',
        'allow_pay_later' => 'boolean',
        'require_customer_for_credit' => 'boolean',
        'guests_mode' => GuestsMode::class,
    ];

    public function allowsCredit(): bool
    {
        return (bool) ($this->allow_partial_payment || $this->allow_pay_later);
    }

    public function allowsPartialPayment(): bool
    {
        return (bool) $this->allow_partial_payment;
    }

    public function allowsPayLater(): bool
    {
        return (bool) $this->allow_pay_later;
    }

    public function requiresCustomerForCredit(): bool
    {
        return (bool) $this->require_customer_for_credit;
    }

    /**
     * Instant sale and web orders never pass through the cashier's open-order
     * step, so they cannot ask for a guest count.
     */
    public static function supportsGuests(OrderBehavior|string|null $behavior): bool
    {
        $behavior = $behavior instanceof OrderBehavior ? $behavior : OrderBehavior::tryFrom((string) $behavior);

        return ! in_array($behavior, [OrderBehavior::INSTANT, OrderBehavior::WEB_DELIVERY, OrderBehavior::WEB_TAKEAWAY], true);
    }

    public function guestsMode(): GuestsMode
    {
        if (! self::supportsGuests($this->behavior)) {
            return GuestsMode::OFF;
        }

        // Rows built without the column (or not yet refreshed) read as off.
        return $this->getAttribute('guests_mode') ?? GuestsMode::OFF;
    }

    public function asksForGuests(): bool
    {
        return $this->guestsMode() !== GuestsMode::OFF;
    }

    public function requiresGuests(): bool
    {
        return $this->guestsMode() === GuestsMode::REQUIRED;
    }
}
