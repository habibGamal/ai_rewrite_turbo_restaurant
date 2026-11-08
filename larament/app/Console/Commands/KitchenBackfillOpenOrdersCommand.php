<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Kitchen\KitchenTicketService;
use Illuminate\Console\Command;

class KitchenBackfillOpenOrdersCommand extends Command
{
    protected $signature = 'kitchen:backfill-open-orders';

    protected $description = 'Create kitchen tickets for processing orders that have items but no ticket';

    public function handle(KitchenTicketService $kitchenTicketService): int
    {
        $created = 0;

        Order::query()
            ->where('status', OrderStatus::PROCESSING)
            ->whereHas('items')
            ->whereDoesntHave('kitchenTicket')
            ->chunkById(100, function ($orders) use ($kitchenTicketService, &$created): void {
                foreach ($orders as $order) {
                    $ticket = $kitchenTicketService->syncOrder($order);
                    if ($ticket !== null) {
                        $created++;
                    }
                }
            });

        $this->info("Backfilled {$created} kitchen ticket(s).");

        return self::SUCCESS;
    }
}
