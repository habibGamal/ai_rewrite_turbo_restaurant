<?php

namespace Database\Factories;

use App\Enums\KitchenItemKind;
use App\Enums\KitchenItemStatus;
use App\Models\KitchenStation;
use App\Models\KitchenTicket;
use App\Models\KitchenTicketItem;
use App\Models\KitchenTicketRound;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KitchenTicketItem>
 */
class KitchenTicketItemFactory extends Factory
{
    protected $model = KitchenTicketItem::class;

    public function definition(): array
    {
        return [
            'kitchen_ticket_id' => KitchenTicket::factory(),
            'kitchen_ticket_round_id' => function (array $attributes): int {
                return KitchenTicketRound::factory()->create([
                    'kitchen_ticket_id' => $attributes['kitchen_ticket_id'],
                ])->id;
            },
            'product_id' => Product::factory(),
            'product_name' => $this->faker->words(2, true),
            'kitchen_station_id' => KitchenStation::factory(),
            'quantity' => 1,
            'original_quantity' => 1,
            'notes' => null,
            'kind' => KitchenItemKind::ORIGINAL,
            'status' => KitchenItemStatus::PENDING,
            'target_minutes' => 10,
            'sent_at' => now(),
            'done_at' => null,
            'voided_at' => null,
            'done_by' => null,
            'voided_after_done' => false,
            'needs_ack' => false,
            'auto_finished' => false,
        ];
    }

    public function done(): static
    {
        return $this->state(fn (): array => [
            'status' => KitchenItemStatus::DONE,
            'done_at' => now(),
            'done_by' => User::factory(),
        ]);
    }

    public function voided(): static
    {
        return $this->state(fn (): array => [
            'status' => KitchenItemStatus::VOIDED,
            'voided_at' => now(),
        ]);
    }
}
