<?php

namespace App\Modules\Restaurant\Http\Resources;

use App\Models\Customer;
use App\Models\KitchenTicket;
use App\Models\KitchenTicketItem;
use App\Models\KitchenTicketRound;
use App\Models\Order;
use App\Modules\Restaurant\Services\KitchenTicketService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RuntimeException;

class KitchenTicketResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var KitchenTicket $ticket */
        $ticket = $this->resource;
        $order = $ticket->order;
        if (! $order instanceof Order) {
            throw new RuntimeException('Kitchen ticket is missing its order.');
        }
        $customer = $order->customer;
        $service = $this->kitchen();

        return [
            'id' => $ticket->id,
            'order_id' => $ticket->order_id,
            'order_number' => (int) $order->order_number,
            'order_type' => $order->type,
            'order_type_label' => $order->getOrderTypeLabel(),
            'table_number' => $order->dine_table_number,
            'customer_name' => $customer instanceof Customer ? $customer->name : null,
            'kitchen_notes' => $order->kitchen_notes,
            'status' => $ticket->status->value,
            'is_rush' => $ticket->is_rush,
            'first_sent_at' => $ticket->first_sent_at->toIso8601String(),
            'done_at' => $ticket->done_at?->toIso8601String(),
            'cancelled_at' => $ticket->cancelled_at?->toIso8601String(),
            'seen_at' => $ticket->seen_at?->toIso8601String(),
            'cancel_acknowledged_at' => $ticket->cancel_acknowledged_at?->toIso8601String(),
            'auto_finished' => $ticket->auto_finished,
            'closed_rounds_seconds' => $service->closedRoundsSeconds($ticket),
            'open_round_target_minutes' => $service->openRoundTargetMinutes($ticket),
            'rounds' => $this->rounds($ticket)
                ->sortBy('number')
                ->values()
                ->map(fn (KitchenTicketRound $round): array => [
                    'id' => $round->id,
                    'number' => $round->number,
                    'started_at' => $round->started_at->toIso8601String(),
                    'ended_at' => $round->ended_at?->toIso8601String(),
                ])
                ->all(),
            'items' => $this->items($ticket)
                ->sortBy('id')
                ->values()
                ->map(fn (KitchenTicketItem $item): array => [
                    'id' => $item->id,
                    'round_id' => $item->kitchen_ticket_round_id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'station_id' => $item->kitchen_station_id,
                    'quantity' => (float) $item->quantity,
                    'original_quantity' => (float) $item->original_quantity,
                    'notes' => $item->notes,
                    'kind' => $item->kind->value,
                    'status' => $item->status->value,
                    'target_minutes' => $item->target_minutes,
                    'sent_at' => $item->sent_at->toIso8601String(),
                    'done_at' => $item->done_at?->toIso8601String(),
                    'voided_at' => $item->voided_at?->toIso8601String(),
                    'voided_after_done' => $item->voided_after_done,
                    'needs_ack' => $item->needs_ack,
                    'auto_finished' => $item->auto_finished,
                ])
                ->all(),
        ];
    }

    private function kitchen(): KitchenTicketService
    {
        return app(KitchenTicketService::class);
    }

    /**
     * @return \Illuminate\Support\Collection<int, KitchenTicketRound>
     */
    private function rounds(KitchenTicket $ticket): \Illuminate\Support\Collection
    {
        /** @var \Illuminate\Support\Collection<int, KitchenTicketRound> $rounds */
        $rounds = $ticket->rounds;

        return $rounds;
    }

    /**
     * @return \Illuminate\Support\Collection<int, KitchenTicketItem>
     */
    private function items(KitchenTicket $ticket): \Illuminate\Support\Collection
    {
        /** @var \Illuminate\Support\Collection<int, KitchenTicketItem> $items */
        $items = $ticket->items;

        return $items;
    }
}
