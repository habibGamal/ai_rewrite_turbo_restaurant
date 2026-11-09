<?php

namespace App\Orders;

use App\Enums\OrderBehavior;
use App\Orders\Behaviors\CompaniesBehavior;
use App\Orders\Behaviors\CounterBehavior;
use App\Orders\Behaviors\DeliveryBehavior;
use App\Orders\Behaviors\DineInBehavior;
use App\Orders\Behaviors\OrderTypeBehavior;
use App\Orders\Behaviors\WebDeliveryBehavior;
use App\Orders\Behaviors\WebTakeawayBehavior;

final class OrderBehaviorResolver
{
    public function __construct(
        private readonly DineInBehavior $dineIn,
        private readonly DeliveryBehavior $delivery,
        private readonly CounterBehavior $counter,
        private readonly CompaniesBehavior $companies,
        private readonly WebDeliveryBehavior $webDelivery,
        private readonly WebTakeawayBehavior $webTakeaway,
    ) {}

    public function resolve(OrderBehavior $behavior): OrderTypeBehavior
    {
        return match ($behavior) {
            OrderBehavior::DINE_IN => $this->dineIn,
            OrderBehavior::DELIVERY => $this->delivery,
            OrderBehavior::COUNTER => $this->counter,
            OrderBehavior::COMPANIES => $this->companies,
            OrderBehavior::WEB_DELIVERY => $this->webDelivery,
            OrderBehavior::WEB_TAKEAWAY => $this->webTakeaway,
        };
    }
}
