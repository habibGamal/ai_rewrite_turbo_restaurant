<?php

namespace App\Services\Kitchen;

use App\Enums\KitchenItemKind;
use App\Enums\KitchenItemStatus;
use App\Enums\KitchenTicketStatus;
use App\Enums\OrderStatus;
use App\Enums\SettingKey;
use App\Events\Kitchen\KitchenTicketUpdated;
use App\Exceptions\KitchenException;
use App\Models\KitchenTicket;
use App\Models\KitchenTicketItem;
use App\Models\KitchenTicketRound;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class KitchenTicketService
{
    public function __construct(
        private readonly KitchenRoutingService $routing,
    ) {}

    /**
     * Reconcile a processing order's items onto its kitchen ticket.
     * Items are matched by product_id because order_items are deleted and recreated on save.
     */
    public function syncOrder(Order $order): ?KitchenTicket
    {
        return DB::transaction(function () use ($order): ?KitchenTicket {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status !== OrderStatus::PROCESSING) {
                return KitchenTicket::query()->where('order_id', $order->id)->first();
            }

            $ticket = KitchenTicket::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first();

            if ($ticket !== null && $ticket->status === KitchenTicketStatus::CANCELLED) {
                return $ticket;
            }

            $order->load(['items.product.category']);
            $desired = $this->desiredByProduct($order);

            if ($ticket === null) {
                if ($desired === []) {
                    return null;
                }

                $ticket = $this->createTicket($order, $desired);
                $this->broadcast($ticket, KitchenTicketUpdated::REASON_NEW);

                return $ticket->refresh();
            }

            $mutated = $this->reconcile($ticket, $order, $desired);
            $closed = $this->closeRoundIfIdle($ticket);

            if (! $mutated && ! $closed) {
                return $ticket->refresh();
            }

            $reason = $mutated
                ? KitchenTicketUpdated::REASON_CHANGED
                : KitchenTicketUpdated::REASON_DONE;
            $this->broadcast($ticket, $reason);

            return $ticket->refresh();
        });
    }

    public function finishItem(KitchenTicketItem $item, User $user): KitchenTicketItem
    {
        return DB::transaction(function () use ($item, $user): KitchenTicketItem {
            $this->assertItemTracking();
            [$ticket, $item] = $this->lockItem($item);
            $this->assertTicketActive($ticket);

            if (! $item->status->isOpen()) {
                throw new KitchenException('لا يمكن إنهاء هذا الصنف.');
            }

            $item->status = KitchenItemStatus::DONE;
            $item->done_at = now();
            $item->done_by = $user->id;
            $item->auto_finished = false;
            $item->save();

            $closed = $this->closeRoundIfIdle($ticket);
            $this->broadcast(
                $ticket,
                $closed ? KitchenTicketUpdated::REASON_DONE : KitchenTicketUpdated::REASON_CHANGED,
            );

            return $item->refresh();
        });
    }

    public function undoItem(KitchenTicketItem $item): KitchenTicketItem
    {
        return DB::transaction(function () use ($item): KitchenTicketItem {
            [$ticket, $item] = $this->lockItem($item);

            if ($ticket->status === KitchenTicketStatus::CANCELLED) {
                throw new KitchenException('لا يمكن التراجع عن صنف في تذكرة ملغاة.');
            }

            if ($item->status !== KitchenItemStatus::DONE) {
                throw new KitchenException('لا يمكن التراجع إلا عن صنف منتهٍ.');
            }

            if (! $this->withinRecall($item->done_at)) {
                throw new KitchenException('انتهت مهلة التراجع عن هذا الصنف.');
            }

            $item->status = KitchenItemStatus::PENDING;
            $item->done_at = null;
            $item->done_by = null;
            $item->auto_finished = false;
            $item->save();

            $round = KitchenTicketRound::query()
                ->whereKey($item->kitchen_ticket_round_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($round->ended_at !== null) {
                $round->ended_at = null;
                $round->save();
            }

            if ($ticket->status !== KitchenTicketStatus::ACTIVE || $ticket->done_at !== null) {
                $ticket->status = KitchenTicketStatus::ACTIVE;
                $ticket->done_at = null;
                $ticket->auto_finished = false;
                $ticket->save();
            }

            $this->broadcast($ticket, KitchenTicketUpdated::REASON_CHANGED);

            return $item->refresh();
        });
    }

    public function finishTicket(KitchenTicket $ticket, ?int $stationId, User $user): KitchenTicket
    {
        return DB::transaction(function () use ($ticket, $stationId, $user): KitchenTicket {
            $ticket = $this->lockTicket($ticket->id);
            $this->assertTicketActive($ticket);

            $query = KitchenTicketItem::query()
                ->where('kitchen_ticket_id', $ticket->id)
                ->whereIn('status', [
                    KitchenItemStatus::PENDING->value,
                ])
                ->lockForUpdate();

            if ($stationId !== null) {
                $query->where('kitchen_station_id', $stationId);
            }

            $items = $query->get();
            if ($items->isEmpty()) {
                return $ticket;
            }

            $now = now();
            foreach ($items as $item) {
                $item->status = KitchenItemStatus::DONE;
                $item->done_at = $now;
                $item->done_by = $user->id;
                $item->auto_finished = false;
                $item->save();
            }

            $closed = $this->closeRoundIfIdle($ticket);
            $this->broadcast(
                $ticket,
                $closed ? KitchenTicketUpdated::REASON_DONE : KitchenTicketUpdated::REASON_CHANGED,
            );

            return $ticket->refresh();
        });
    }

    public function acknowledge(KitchenTicket $ticket): KitchenTicket
    {
        return DB::transaction(function () use ($ticket): KitchenTicket {
            $ticket = $this->lockTicket($ticket->id);
            $ticket->seen_at = now();

            if ($ticket->status === KitchenTicketStatus::CANCELLED) {
                $ticket->cancel_acknowledged_at = now();
            }

            $ticket->save();

            KitchenTicketItem::query()
                ->where('kitchen_ticket_id', $ticket->id)
                ->where('needs_ack', true)
                ->update(['needs_ack' => false]);

            $this->broadcast($ticket, KitchenTicketUpdated::REASON_CHANGED);

            return $ticket->refresh();
        });
    }

    public function setRush(KitchenTicket $ticket, bool $isRush): KitchenTicket
    {
        return DB::transaction(function () use ($ticket, $isRush): KitchenTicket {
            $ticket = $this->lockTicket($ticket->id);

            if ($ticket->is_rush === $isRush) {
                return $ticket;
            }

            $ticket->is_rush = $isRush;
            $ticket->save();
            $this->broadcast($ticket, KitchenTicketUpdated::REASON_CHANGED);

            return $ticket->refresh();
        });
    }

    public function cancelForOrder(Order $order): ?KitchenTicket
    {
        return DB::transaction(function () use ($order): ?KitchenTicket {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if ($order === null) {
                return null;
            }

            $ticket = KitchenTicket::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first();

            if ($ticket === null) {
                return null;
            }

            $changed = false;
            if ($ticket->status !== KitchenTicketStatus::CANCELLED) {
                $ticket->status = KitchenTicketStatus::CANCELLED;
                $ticket->cancelled_at = now();
                $ticket->save();
                $changed = true;
            }

            $items = KitchenTicketItem::query()
                ->where('kitchen_ticket_id', $ticket->id)
                ->lockForUpdate()
                ->get();

            foreach ($items as $item) {
                if ($item->status === KitchenItemStatus::VOIDED) {
                    continue;
                }

                $this->voidLine($item, $item->status === KitchenItemStatus::DONE);
                $changed = true;
            }

            if ($this->freezeOpenRound($ticket)) {
                $changed = true;
            }

            if ($changed) {
                $this->broadcast($ticket, KitchenTicketUpdated::REASON_CANCELLED);
            }

            return $ticket->refresh();
        });
    }

    public function autoFinishForOrder(Order $order): ?KitchenTicket
    {
        if (setting(SettingKey::KDS_AUTO_FINISH_ON_COMPLETE) !== 'true') {
            return KitchenTicket::query()->where('order_id', $order->id)->first();
        }

        return DB::transaction(function () use ($order): ?KitchenTicket {
            $ticket = KitchenTicket::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first();

            if ($ticket === null || $ticket->status === KitchenTicketStatus::CANCELLED) {
                return $ticket;
            }

            $open = KitchenTicketItem::query()
                ->where('kitchen_ticket_id', $ticket->id)
                ->whereIn('status', [
                    KitchenItemStatus::PENDING->value,
                ])
                ->lockForUpdate()
                ->get();

            if ($open->isEmpty()) {
                return $ticket;
            }

            $now = now();
            foreach ($open as $item) {
                $item->status = KitchenItemStatus::DONE;
                $item->done_at = $now;
                $item->done_by = null;
                $item->auto_finished = true;
                $item->save();
            }

            $closed = $this->closeRoundIfIdle($ticket);
            if ($closed) {
                $ticket->auto_finished = true;
                $ticket->save();
            }

            $this->broadcast(
                $ticket,
                $closed ? KitchenTicketUpdated::REASON_DONE : KitchenTicketUpdated::REASON_CHANGED,
            );

            return $ticket->refresh();
        });
    }

    public function rebroadcastForOrder(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $ticket = KitchenTicket::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first();

            if ($ticket === null) {
                return;
            }

            $this->broadcast($ticket, KitchenTicketUpdated::REASON_CHANGED);
        });
    }

    public function closedRoundsSeconds(KitchenTicket $ticket): int
    {
        $seconds = 0;
        foreach ($this->roundsOf($ticket) as $round) {
            if ($round->ended_at === null) {
                continue;
            }

            $seconds += (int) round($round->started_at->diffInSeconds($round->ended_at, true));
        }

        return $seconds;
    }

    public function openRoundTargetMinutes(KitchenTicket $ticket): ?int
    {
        $open = $this->roundsOf($ticket)
            ->filter(fn (KitchenTicketRound $round): bool => $round->ended_at === null)
            ->sortByDesc('number')
            ->first();

        if (! $open instanceof KitchenTicketRound) {
            return null;
        }

        $targets = $this->itemsOf($ticket)
            ->filter(fn (KitchenTicketItem $item): bool => $item->kitchen_ticket_round_id === $open->id && $item->status->isOpen())
            ->map(fn (KitchenTicketItem $item): int => $item->target_minutes);

        if ($targets->isEmpty()) {
            return null;
        }

        return (int) $targets->max();
    }

    /**
     * @param  array<int, array{product: Product, quantity: int, notes: ?string}>  $desired
     */
    private function createTicket(Order $order, array $desired): KitchenTicket
    {
        $now = now();
        $ticket = KitchenTicket::query()->create([
            'order_id' => $order->id,
            'status' => KitchenTicketStatus::ACTIVE,
            'is_rush' => false,
            'first_sent_at' => $now,
            'auto_finished' => false,
        ]);

        $round = KitchenTicketRound::query()->create([
            'kitchen_ticket_id' => $ticket->id,
            'number' => 1,
            'started_at' => $now,
        ]);

        foreach ($desired as $line) {
            $this->createLine(
                $ticket,
                $round,
                $line['product'],
                $order,
                $line['quantity'],
                KitchenItemKind::ORIGINAL,
                $line['notes'],
            );
        }

        return $ticket;
    }

    /**
     * @param  array<int, array{product: Product, quantity: int, notes: ?string}>  $desired
     */
    private function reconcile(KitchenTicket $ticket, Order $order, array $desired): bool
    {
        $changed = false;
        $lines = KitchenTicketItem::query()
            ->where('kitchen_ticket_id', $ticket->id)
            ->lockForUpdate()
            ->get();

        $nonVoided = $lines
            ->filter(fn (KitchenTicketItem $item): bool => $item->status !== KitchenItemStatus::VOIDED)
            ->values();

        $orphans = $nonVoided
            ->filter(fn (KitchenTicketItem $item): bool => $item->product_id === null)
            ->values();

        if ($orphans->isNotEmpty()) {
            $changed = $this->decrease($orphans, $this->sumMilli($orphans));
        }

        $productIds = collect(array_keys($desired))
            ->merge($nonVoided->pluck('product_id')->filter())
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        foreach ($productIds as $productId) {
            $productLines = $nonVoided
                ->filter(fn (KitchenTicketItem $item): bool => (int) $item->product_id === $productId)
                ->values();
            $current = $this->sumMilli($productLines);
            $known = array_key_exists($productId, $desired);
            $target = $known ? $desired[$productId]['quantity'] : 0;
            $notes = $known ? $desired[$productId]['notes'] : $this->currentNotes($productLines);

            if ($known && $target > $current) {
                $product = $desired[$productId]['product'];
                $kind = $productLines->isEmpty()
                    ? KitchenItemKind::ADDED
                    : KitchenItemKind::INCREASED;
                $round = $this->openRound($ticket);
                $this->createLine($ticket, $round, $product, $order, $target - $current, $kind, $notes);
                $changed = true;
            } elseif ($target < $current) {
                $changed = $this->decrease($productLines, $current - $target) || $changed;
            }

            if ($this->applyNotes($productLines, $notes)) {
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * @param  Collection<int, KitchenTicketItem>  $lines
     */
    private function applyNotes(Collection $lines, ?string $notes): bool
    {
        $changed = false;

        foreach ($lines as $line) {
            if ($line->status === KitchenItemStatus::VOIDED) {
                continue;
            }

            if ($this->normalizeNotes($line->notes) === $notes) {
                continue;
            }

            if ($line->status->isOpen()) {
                $line->notes = $notes;
            }

            $line->needs_ack = true;
            $line->save();
            $changed = true;
        }

        return $changed;
    }

    /**
     * @param  Collection<int, KitchenTicketItem>  $lines
     */
    private function decrease(Collection $lines, int $removeMilli): bool
    {
        if ($removeMilli <= 0) {
            return false;
        }

        $changed = false;

        foreach ($this->newestFirst($lines) as $line) {
            if ($removeMilli <= 0) {
                break;
            }

            if (! $line->status->isOpen()) {
                continue;
            }

            $removeMilli = $this->shrinkOrVoidOpen($line, $removeMilli);
            $changed = true;
        }

        foreach ($this->newestFirst($lines) as $line) {
            if ($removeMilli <= 0) {
                break;
            }

            if ($line->status !== KitchenItemStatus::DONE) {
                continue;
            }

            $removeMilli = $this->voidOrSplitDone($line, $removeMilli);
            $changed = true;
        }

        return $changed;
    }

    private function shrinkOrVoidOpen(KitchenTicketItem $line, int $removeMilli): int
    {
        $quantity = $this->toMilli($line->quantity);

        if ($removeMilli >= $quantity) {
            $this->voidLine($line, false);

            return $removeMilli - $quantity;
        }

        $line->quantity = $this->fromMilli($quantity - $removeMilli);
        $line->needs_ack = true;
        $line->save();

        return 0;
    }

    private function voidOrSplitDone(KitchenTicketItem $line, int $removeMilli): int
    {
        $quantity = $this->toMilli($line->quantity);

        if ($removeMilli >= $quantity) {
            $this->voidLine($line, true);

            return $removeMilli - $quantity;
        }

        $this->splitDoneLine($line, $removeMilli);

        return 0;
    }

    private function voidLine(KitchenTicketItem $line, bool $afterDone): void
    {
        $line->status = KitchenItemStatus::VOIDED;
        $line->voided_at = now();
        $line->voided_after_done = $afterDone;
        $line->needs_ack = true;
        $line->save();
    }

    /**
     * Keep the unremoved portion done, and record the removed portion as waste.
     */
    private function splitDoneLine(KitchenTicketItem $line, int $removeMilli): void
    {
        $quantity = $this->toMilli($line->quantity);
        $original = $this->toMilli($line->original_quantity);
        $remaining = $quantity - $removeMilli;
        $voided = $line->replicate();

        $line->quantity = $this->fromMilli($remaining);
        $line->original_quantity = $this->fromMilli(max($remaining, $original - $removeMilli));
        $line->save();

        $voided->quantity = $this->fromMilli($removeMilli);
        $voided->original_quantity = $this->fromMilli($removeMilli);
        $voided->status = KitchenItemStatus::VOIDED;
        $voided->voided_at = now();
        $voided->voided_after_done = true;
        $voided->needs_ack = true;
        $voided->save();
    }

    private function openRound(KitchenTicket $ticket): KitchenTicketRound
    {
        $open = KitchenTicketRound::query()
            ->where('kitchen_ticket_id', $ticket->id)
            ->whereNull('ended_at')
            ->lockForUpdate()
            ->orderByDesc('number')
            ->first();

        if ($open !== null) {
            return $open;
        }

        $max = (int) KitchenTicketRound::query()
            ->where('kitchen_ticket_id', $ticket->id)
            ->max('number');

        if ($ticket->status !== KitchenTicketStatus::ACTIVE || $ticket->done_at !== null || $ticket->auto_finished) {
            $ticket->status = KitchenTicketStatus::ACTIVE;
            $ticket->done_at = null;
            $ticket->auto_finished = false;
            $ticket->save();
        }

        return KitchenTicketRound::query()->create([
            'kitchen_ticket_id' => $ticket->id,
            'number' => $max + 1,
            'started_at' => now(),
        ]);
    }

    private function closeRoundIfIdle(KitchenTicket $ticket): bool
    {
        if ($ticket->status === KitchenTicketStatus::CANCELLED) {
            return false;
        }

        $hasOpen = KitchenTicketItem::query()
            ->where('kitchen_ticket_id', $ticket->id)
            ->whereIn('status', [
                KitchenItemStatus::PENDING->value,
            ])
            ->exists();

        if ($hasOpen) {
            return false;
        }

        $changed = false;

        // An undo can reopen an earlier round while a later one is also open,
        // so close every open round, not only the newest.
        $openRounds = KitchenTicketRound::query()
            ->where('kitchen_ticket_id', $ticket->id)
            ->whereNull('ended_at')
            ->lockForUpdate()
            ->get();

        foreach ($openRounds as $openRound) {
            $openRound->ended_at = now();
            $openRound->save();
            $changed = true;
        }

        if ($ticket->status !== KitchenTicketStatus::DONE) {
            $ticket->status = KitchenTicketStatus::DONE;
            $ticket->done_at = now();
            $ticket->save();
            $changed = true;
        }

        return $changed;
    }

    private function freezeOpenRound(KitchenTicket $ticket): bool
    {
        $openRound = KitchenTicketRound::query()
            ->where('kitchen_ticket_id', $ticket->id)
            ->whereNull('ended_at')
            ->lockForUpdate()
            ->first();

        if ($openRound === null) {
            return false;
        }

        $openRound->ended_at = now();
        $openRound->save();

        return true;
    }

    private function createLine(
        KitchenTicket $ticket,
        KitchenTicketRound $round,
        Product $product,
        Order $order,
        int $quantityMilli,
        KitchenItemKind $kind,
        ?string $notes,
    ): KitchenTicketItem {
        return KitchenTicketItem::query()->create([
            'kitchen_ticket_id' => $ticket->id,
            'kitchen_ticket_round_id' => $round->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'kitchen_station_id' => $this->routing->stationId($product),
            'quantity' => $this->fromMilli($quantityMilli),
            'original_quantity' => $this->fromMilli($quantityMilli),
            'notes' => $notes,
            'kind' => $kind,
            'status' => KitchenItemStatus::PENDING,
            'target_minutes' => $this->routing->targetMinutes($product, $order->type),
            'sent_at' => now(),
            'voided_after_done' => false,
            'needs_ack' => false,
            'auto_finished' => false,
        ]);
    }

    /**
     * @param  'new'|'changed'|'cancelled'|'done'  $reason
     */
    private function broadcast(KitchenTicket $ticket, string $reason): void
    {
        $ticket->load(['order', 'items']);
        $order = $ticket->order;
        if (! $order instanceof Order) {
            return;
        }

        $shiftId = $order->shift_id ?? app(\App\Services\ShiftService::class)->getCurrentShift()?->id;

        KitchenTicketUpdated::dispatch(
            $ticket->id,
            (int) $order->order_number,
            $reason,
            $this->stationIds($ticket),
            $shiftId,
        );
    }

    /**
     * @return list<int>
     */
    private function stationIds(KitchenTicket $ticket): array
    {
        return $ticket->items
            ->pluck('kitchen_station_id')
            ->filter(static fn ($id): bool => $id !== null)
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{product: Product, quantity: int, notes: ?string}>
     */
    private function desiredByProduct(Order $order): array
    {
        /** @var array<int, array{product: Product, quantity: int, notes: list<string>}> $grouped */
        $grouped = [];

        foreach ($order->items as $item) {
            if (! $item instanceof OrderItem) {
                continue;
            }

            $product = $item->product;
            if (! $product instanceof Product || ! $this->routing->shownOnKds($product)) {
                continue;
            }

            $quantity = $this->toMilli($item->quantity);
            if ($quantity <= 0) {
                continue;
            }

            if (! isset($grouped[$product->id])) {
                $grouped[$product->id] = [
                    'product' => $product,
                    'quantity' => 0,
                    'notes' => [],
                ];
            }

            $grouped[$product->id]['quantity'] += $quantity;
            $note = $this->normalizeNotes($item->notes);
            if ($note !== null) {
                $grouped[$product->id]['notes'][] = $note;
            }
        }

        $desired = [];
        foreach ($grouped as $productId => $line) {
            $notes = collect($line['notes'])->unique()->values();
            $desired[$productId] = [
                'product' => $line['product'],
                'quantity' => $line['quantity'],
                'notes' => $notes->isEmpty() ? null : $notes->implode(' | '),
            ];
        }

        return $desired;
    }

    /**
     * @param  Collection<int, KitchenTicketItem>  $lines
     */
    private function currentNotes(Collection $lines): ?string
    {
        $notes = $lines
            ->map(fn (KitchenTicketItem $item): ?string => $this->normalizeNotes($item->notes))
            ->filter(fn (?string $note): bool => $note !== null)
            ->unique()
            ->values();

        return $notes->isEmpty() ? null : $notes->implode(' | ');
    }

    /**
     * @param  Collection<int, KitchenTicketItem>  $lines
     */
    private function newestFirst(Collection $lines): Collection
    {
        return $lines
            ->sort(function (KitchenTicketItem $left, KitchenTicketItem $right): int {
                $bySent = $right->sent_at <=> $left->sent_at;

                return $bySent !== 0 ? $bySent : $right->id <=> $left->id;
            })
            ->values();
    }

    /**
     * @param  Collection<int, KitchenTicketItem>  $lines
     */
    private function sumMilli(Collection $lines): int
    {
        $sum = 0;
        foreach ($lines as $line) {
            $sum += $this->toMilli($line->quantity);
        }

        return $sum;
    }

    private function lockTicket(int $ticketId): KitchenTicket
    {
        return KitchenTicket::query()->whereKey($ticketId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Lock the ticket before the line so concurrent screens cannot deadlock with syncOrder.
     *
     * @return array{0: KitchenTicket, 1: KitchenTicketItem}
     */
    private function lockItem(KitchenTicketItem $item): array
    {
        $ticketId = (int) KitchenTicketItem::query()->whereKey($item->id)->value('kitchen_ticket_id');
        $ticket = $this->lockTicket($ticketId);
        $locked = KitchenTicketItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

        return [$ticket, $locked];
    }

    private function assertTicketActive(KitchenTicket $ticket): void
    {
        if ($ticket->status !== KitchenTicketStatus::ACTIVE) {
            throw new KitchenException('التذكرة غير نشطة.');
        }
    }

    private function assertItemTracking(): void
    {
        if (setting(SettingKey::KDS_ITEM_TRACKING) !== 'true') {
            throw new KitchenException('تتبع الأصناف غير مفعّل. يمكن إنهاء التذكرة كاملة فقط.');
        }
    }

    private function withinRecall(?Carbon $doneAt): bool
    {
        if ($doneAt === null) {
            return false;
        }

        $minutes = (int) setting(SettingKey::KDS_RECALL_MINUTES);

        return now()->lte($doneAt->copy()->addMinutes($minutes));
    }

    private function normalizeNotes(mixed $notes): ?string
    {
        if (! is_string($notes)) {
            return null;
        }

        $trimmed = trim($notes);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return Collection<int, KitchenTicketRound>
     */
    private function roundsOf(KitchenTicket $ticket): Collection
    {
        $rounds = $ticket->relationLoaded('rounds')
            ? $ticket->rounds
            : $ticket->rounds()->get();

        /** @var Collection<int, KitchenTicketRound> $typed */
        $typed = $rounds;

        return $typed;
    }

    /**
     * @return Collection<int, KitchenTicketItem>
     */
    private function itemsOf(KitchenTicket $ticket): Collection
    {
        $items = $ticket->relationLoaded('items')
            ? $ticket->items
            : $ticket->items()->get();

        /** @var Collection<int, KitchenTicketItem> $typed */
        $typed = $items;

        return $typed;
    }

    private function toMilli(float|int|string $value): int
    {
        return (int) round(((float) $value) * 1000);
    }

    private function fromMilli(int $milli): float
    {
        return round($milli / 1000, 3);
    }
}
