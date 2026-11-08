<?php

namespace Database\Factories;

use App\Models\KitchenTicket;
use App\Models\KitchenTicketRound;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KitchenTicketRound>
 */
class KitchenTicketRoundFactory extends Factory
{
    protected $model = KitchenTicketRound::class;

    public function definition(): array
    {
        return [
            'kitchen_ticket_id' => KitchenTicket::factory(),
            'number' => function (array $attributes): int {
                $max = KitchenTicketRound::query()
                    ->where('kitchen_ticket_id', $attributes['kitchen_ticket_id'])
                    ->max('number');

                return ((int) $max) + 1;
            },
            'started_at' => now(),
            'ended_at' => null,
        ];
    }
}
