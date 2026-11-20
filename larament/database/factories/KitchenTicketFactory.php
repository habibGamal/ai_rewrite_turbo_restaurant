<?php

namespace Database\Factories;

use App\Enums\KitchenTicketStatus;
use App\Models\KitchenTicket;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KitchenTicket>
 */
class KitchenTicketFactory extends Factory
{
    protected $model = KitchenTicket::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'status' => KitchenTicketStatus::ACTIVE,
            'is_rush' => false,
            'first_sent_at' => now(),
            'done_at' => null,
            'cancelled_at' => null,
            'seen_at' => null,
            'cancel_acknowledged_at' => null,
            'auto_finished' => false,
        ];
    }
}
