<?php

namespace App\Services;

use App\Enums\KitchenItemStatus;
use App\Enums\KitchenTicketStatus;
use App\Enums\OrderType;
use App\Models\KitchenStation;
use App\Models\KitchenTicket;
use App\Models\KitchenTicketItem;
use App\Models\Order;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Builds the kitchen performance report from the kitchen tables.
 *
 * All durations are computed in PHP from the stored timestamps so the report
 * runs on both MySQL (production) and SQLite (tests) without driver-specific
 * SQL date functions.
 *
 * Semantics:
 * - Kitchen order time is the sum of a ticket's closed rounds
 *   (ended_at - started_at). Idle gaps between rounds never count.
 * - A closed round is late when its duration exceeds the largest target_minutes
 *   among its non-voided items (optionally limited to one station).
 * - Item prep time is done_at - (started_at ?? sent_at) for done items that
 *   were not auto-finished.
 * - Waste candidates are items flagged voided_after_done, including items on
 *   tickets that were cancelled after they had been prepared.
 * - Cancelled tickets are excluded from every time metric.
 */
class KitchenPerformanceReportService
{
    /** @var array<string, Collection<int, KitchenTicket>> */
    private array $ticketCache = [];

    /** @var array<string, Collection<int, KitchenTicketItem>> */
    private array $itemCache = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $wasteCache = [];

    /**
     * Period summary.
     *
     * The average kitchen time is taken over tickets that have at least one
     * closed round; tickets still waiting on their first round do not drag it
     * down to zero.
     *
     * @return array{tickets_count: int, avg_kitchen_seconds: float, late_rounds_percent: float, avg_item_prep_seconds: float, waste_candidates_count: int}
     */
    public function getSummary(
        string|DateTimeInterface|null $startDate = null,
        string|DateTimeInterface|null $endDate = null,
        ?int $stationId = null
    ): array {
        $tickets = $this->getTickets($startDate, $endDate, $stationId);

        $lateRounds = 0;
        $evaluatedRounds = 0;
        $ticketDurations = [];

        foreach ($tickets as $ticket) {
            $stats = $this->summarizeTicket($ticket, $stationId);

            $lateRounds += $stats['late_rounds'];
            $evaluatedRounds += $stats['evaluated_rounds'];

            if ($stats['closed_rounds'] > 0) {
                $ticketDurations[] = $stats['seconds'];
            }
        }

        $items = $this->getPerformanceItems($startDate, $endDate, $stationId);

        return [
            'tickets_count' => $tickets->count(),
            'avg_kitchen_seconds' => $this->average($ticketDurations),
            'late_rounds_percent' => $this->percentage($lateRounds, $evaluatedRounds),
            'avg_item_prep_seconds' => $this->average(
                $items->map(fn (KitchenTicketItem $item): float => $this->itemPrepSeconds($item))->all()
            ),
            'waste_candidates_count' => count($this->getWasteCandidates($startDate, $endDate, $stationId)),
        ];
    }

    /**
     * Average kitchen time and lateness per order type.
     *
     * @return array<int, array{type: string, label: string, count: int, avg_kitchen_seconds: float, late_rounds_percent: float}>
     */
    public function getOrderTypePerformance(
        string|DateTimeInterface|null $startDate = null,
        string|DateTimeInterface|null $endDate = null,
        ?int $stationId = null
    ): array {
        $tickets = $this->getTickets($startDate, $endDate, $stationId)
            ->groupBy(function (KitchenTicket $ticket): string {
                $order = $ticket->order;

                return $order instanceof Order ? (string) $order->type : 'unknown';
            });

        $rows = [];

        foreach ($tickets as $type => $group) {
            $lateRounds = 0;
            $evaluatedRounds = 0;
            $durations = [];

            foreach ($group as $ticket) {
                $stats = $this->summarizeTicket($ticket, $stationId);

                $lateRounds += $stats['late_rounds'];
                $evaluatedRounds += $stats['evaluated_rounds'];

                if ($stats['closed_rounds'] > 0) {
                    $durations[] = $stats['seconds'];
                }
            }

            $rows[] = [
                'type' => (string) $type,
                'label' => $this->orderTypeLabel((string) $type),
                'count' => $group->count(),
                'avg_kitchen_seconds' => $this->average($durations),
                'late_rounds_percent' => $this->percentage($lateRounds, $evaluatedRounds),
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $rows;
    }

    /**
     * Slowest products first, based on item prep time.
     *
     * @return array<int, array{product_id: int|null, product_name: string, count: int, avg_prep_seconds: float, max_prep_seconds: float, late_items_percent: float}>
     */
    public function getProductPerformance(
        string|DateTimeInterface|null $startDate = null,
        string|DateTimeInterface|null $endDate = null,
        ?int $stationId = null
    ): array {
        $groups = $this->getPerformanceItems($startDate, $endDate, $stationId)
            ->groupBy(fn (KitchenTicketItem $item): string => $item->product_id !== null
                ? 'id:'.$item->product_id
                : 'name:'.$item->product_name);

        $rows = [];

        foreach ($groups as $group) {
            $first = $group->first();
            $durations = $group
                ->map(fn (KitchenTicketItem $item): float => $this->itemPrepSeconds($item))
                ->all();

            $rows[] = [
                'product_id' => $first->product_id,
                'product_name' => $first->product_name,
                'count' => $group->count(),
                'avg_prep_seconds' => $this->average($durations),
                'max_prep_seconds' => round((float) max($durations), 1),
                'late_items_percent' => $this->lateItemsPercentage($group),
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['avg_prep_seconds'] <=> $a['avg_prep_seconds']);

        return $rows;
    }

    /**
     * Item count and average prep time per station. Null station is "عام".
     *
     * @return array<int, array{station_id: int|null, station_name: string, count: int, avg_prep_seconds: float}>
     */
    public function getStationPerformance(
        string|DateTimeInterface|null $startDate = null,
        string|DateTimeInterface|null $endDate = null,
        ?int $stationId = null
    ): array {
        $groups = $this->getPerformanceItems($startDate, $endDate, $stationId)
            ->groupBy(fn (KitchenTicketItem $item): string => $item->kitchen_station_id === null
                ? 'none'
                : 'id:'.$item->kitchen_station_id);

        $rows = [];

        foreach ($groups as $group) {
            /** @var KitchenTicketItem $first */
            $first = $group->first();
            $station = $first->station;

            $rows[] = [
                'station_id' => $first->kitchen_station_id,
                'station_name' => $station instanceof KitchenStation ? $station->name : 'عام',
                'count' => $group->count(),
                'avg_prep_seconds' => $this->average(
                    $group->map(fn (KitchenTicketItem $item): float => $this->itemPrepSeconds($item))->all()
                ),
            ];
        }

        /** @var array<int, array{station_id: int|null, station_name: string, count: int, avg_prep_seconds: float}> $rows */
        usort($rows, fn (array $a, array $b): int => $b['avg_prep_seconds'] <=> $a['avg_prep_seconds']);

        return $rows;
    }

    /**
     * Items that were made and then voided: waste candidates.
     *
     * @return array<int, array{id: int, product_name: string, quantity: float, station_name: string, target_minutes: int, sent_at: string, voided_at: string|null}>
     */
    public function getWasteCandidates(
        string|DateTimeInterface|null $startDate = null,
        string|DateTimeInterface|null $endDate = null,
        ?int $stationId = null
    ): array {
        $key = $this->periodKey($startDate, $endDate, $stationId);

        if (isset($this->wasteCache[$key])) {
            return $this->wasteCache[$key];
        }

        $start = $this->rangeStart($startDate);
        $end = $this->rangeEnd($endDate);

        $items = KitchenTicketItem::query()
            ->where('voided_after_done', true)
            ->when($stationId !== null, fn (Builder $query) => $query->where('kitchen_station_id', $stationId))
            ->whereHas('kitchenTicket', function (Builder $query) use ($start, $end): void {
                if ($start !== null) {
                    $query->where('first_sent_at', '>=', $start);
                }

                if ($end !== null) {
                    $query->where('first_sent_at', '<=', $end);
                }
            })
            ->with('station:id,name')
            ->orderByDesc('voided_at')
            ->orderByDesc('id')
            ->get();

        $rows = [];

        /** @var KitchenTicketItem $item */
        foreach ($items as $item) {
            $station = $item->station;

            $rows[] = [
                'id' => $item->id,
                'product_name' => $item->product_name,
                'quantity' => (float) $item->quantity,
                'station_name' => $station instanceof KitchenStation ? $station->name : 'عام',
                'target_minutes' => (int) $item->target_minutes,
                'sent_at' => $item->sent_at->format('Y-m-d H:i'),
                'voided_at' => $item->voided_at?->format('Y-m-d H:i'),
            ];
        }

        return $this->wasteCache[$key] = $rows;
    }

    /**
     * Format a duration in seconds as mm:ss (minutes may exceed 59).
     */
    public static function formatDuration(float|int|null $seconds): string
    {
        $total = max(0, (int) round((float) $seconds));

        return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
    }

    /**
     * Non-cancelled tickets whose first send falls inside the period.
     *
     * When a station is selected only tickets that have items in that station
     * are returned.
     *
     * @return Collection<int, KitchenTicket>
     */
    private function getTickets(
        string|DateTimeInterface|null $startDate,
        string|DateTimeInterface|null $endDate,
        ?int $stationId
    ): Collection {
        $key = $this->periodKey($startDate, $endDate, $stationId);

        if (isset($this->ticketCache[$key])) {
            return $this->ticketCache[$key];
        }

        $start = $this->rangeStart($startDate);
        $end = $this->rangeEnd($endDate);

        return $this->ticketCache[$key] = KitchenTicket::query()
            ->where('status', '!=', KitchenTicketStatus::CANCELLED->value)
            ->when($start !== null, fn (Builder $query) => $query->where('first_sent_at', '>=', $start))
            ->when($end !== null, fn (Builder $query) => $query->where('first_sent_at', '<=', $end))
            ->when($stationId !== null, fn (Builder $query) => $query->whereHas(
                'items',
                fn (Builder $items) => $items
                    ->where('kitchen_station_id', $stationId)
                    ->where('status', '!=', KitchenItemStatus::VOIDED->value)
            ))
            ->with([
                'order:id,type,order_number',
                'rounds.items:id,kitchen_ticket_id,kitchen_ticket_round_id,kitchen_station_id,status,target_minutes',
            ])
            ->get();
    }

    /**
     * Done, non-auto-finished items whose done_at falls inside the period,
     * excluding items that belong to a cancelled ticket.
     *
     * @return Collection<int, KitchenTicketItem>
     */
    private function getPerformanceItems(
        string|DateTimeInterface|null $startDate,
        string|DateTimeInterface|null $endDate,
        ?int $stationId
    ): Collection {
        $key = $this->periodKey($startDate, $endDate, $stationId);

        if (isset($this->itemCache[$key])) {
            return $this->itemCache[$key];
        }

        $start = $this->rangeStart($startDate);
        $end = $this->rangeEnd($endDate);

        return $this->itemCache[$key] = KitchenTicketItem::query()
            ->where('status', KitchenItemStatus::DONE->value)
            ->where('auto_finished', false)
            ->whereNotNull('done_at')
            ->when($start !== null, fn (Builder $query) => $query->where('done_at', '>=', $start))
            ->when($end !== null, fn (Builder $query) => $query->where('done_at', '<=', $end))
            ->when($stationId !== null, fn (Builder $query) => $query->where('kitchen_station_id', $stationId))
            ->whereHas('kitchenTicket', fn (Builder $query) => $query->where('status', '!=', KitchenTicketStatus::CANCELLED->value))
            ->with('station:id,name')
            ->get();
    }

    /**
     * @return array{seconds: float, closed_rounds: int, late_rounds: int, evaluated_rounds: int}
     */
    private function summarizeTicket(KitchenTicket $ticket, ?int $stationId): array
    {
        $seconds = 0.0;
        $closedRounds = 0;
        $lateRounds = 0;
        $evaluatedRounds = 0;

        foreach ($ticket->rounds as $round) {
            /** @var \App\Models\KitchenTicketRound $round */
            if ($round->ended_at === null) {
                continue;
            }

            /** @var SupportCollection<int, KitchenTicketItem> $items */
            $items = $round->items
                ->reject(fn ($item): bool => $item instanceof KitchenTicketItem
                    && $item->status === KitchenItemStatus::VOIDED);

            if ($stationId !== null) {
                $items = $items->filter(
                    fn (KitchenTicketItem $item): bool => (int) $item->kitchen_station_id === $stationId
                );

                if ($items->isEmpty()) {
                    continue;
                }
            }

            $duration = max(0, $round->ended_at->getTimestamp() - $round->started_at->getTimestamp());

            $seconds += (float) $duration;
            $closedRounds++;

            $target = $items->pluck('target_minutes')->filter()->max();

            if ($target !== null && (int) $target > 0) {
                $evaluatedRounds++;

                if ($duration > (int) $target * 60) {
                    $lateRounds++;
                }
            }
        }

        return [
            'seconds' => $seconds,
            'closed_rounds' => $closedRounds,
            'late_rounds' => $lateRounds,
            'evaluated_rounds' => $evaluatedRounds,
        ];
    }

    private function itemPrepSeconds(KitchenTicketItem $item): float
    {
        if ($item->done_at === null) {
            return 0.0;
        }

        $startedAt = $item->started_at ?? $item->sent_at;

        return (float) max(0, $item->done_at->getTimestamp() - $startedAt->getTimestamp());
    }

    /**
     * @param  SupportCollection<int, KitchenTicketItem>  $items
     */
    private function lateItemsPercentage(SupportCollection $items): float
    {
        $late = 0;
        $evaluated = 0;

        foreach ($items as $item) {
            if ($item->target_minutes <= 0) {
                continue;
            }

            $evaluated++;

            if ($this->itemPrepSeconds($item) > $item->target_minutes * 60) {
                $late++;
            }
        }

        return $this->percentage($late, $evaluated);
    }

    /**
     * @param  array<int, float>  $values
     */
    private function average(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        return round(array_sum($values) / count($values), 1);
    }

    private function percentage(int $part, int $total): float
    {
        if ($total === 0) {
            return 0.0;
        }

        return round(($part / $total) * 100, 1);
    }

    private function rangeStart(string|DateTimeInterface|null $date): ?Carbon
    {
        return $date === null ? null : Carbon::parse($date)->startOfDay();
    }

    private function rangeEnd(string|DateTimeInterface|null $date): ?Carbon
    {
        return $date === null ? null : Carbon::parse($date)->endOfDay();
    }

    private function periodKey(
        string|DateTimeInterface|null $startDate,
        string|DateTimeInterface|null $endDate,
        ?int $stationId
    ): string {
        $start = $startDate === null ? '' : Carbon::parse($startDate)->toDateString();
        $end = $endDate === null ? '' : Carbon::parse($endDate)->toDateString();

        return $start.'|'.$end.'|'.($stationId ?? 'all');
    }

    private function orderTypeLabel(string $type): string
    {
        return OrderType::tryFrom($type)?->label() ?? 'غير محدد';
    }
}
