import type {
    KdsItem,
    KdsRound,
    KdsSettings,
    KdsStation,
    KdsTicket,
} from '@/types/kitchen';

export type TicketUrgency = 'normal' | 'warning' | 'late';
export type LineAction = 'start' | 'done' | 'undo';

export interface RoundGroup {
    round: KdsRound;
    items: KdsItem[];
}

export interface AllDayRow {
    name: string;
    quantity: number;
}

export interface BoardCounts {
    fresh: number;
    warning: number;
    late: number;
}

const CAIRO_TIME = new Intl.DateTimeFormat('en-GB', {
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
    timeZone: 'Africa/Cairo',
});

const CAIRO_CLOCK = new Intl.DateTimeFormat('en-GB', {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false,
    timeZone: 'Africa/Cairo',
});

export function normalizeDigits(value: string): string {
    return value
        .replace(/[٠-٩]/g, (digit) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit)))
        .replace(/[۰-۹]/g, (digit) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)));
}

export function formatQty(value: number): string {
    const quantity = Number(value);
    if (!Number.isFinite(quantity)) {
        return '0';
    }
    if (Number.isInteger(quantity)) {
        return String(quantity);
    }
    return String(Number(quantity.toFixed(3)));
}

export function formatDuration(totalSeconds: number): string {
    const seconds = Math.max(0, Math.floor(totalSeconds));
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;
    const mm = String(minutes).padStart(2, '0');
    const ss = String(secs).padStart(2, '0');
    if (hours > 0) {
        return `${hours}:${mm}:${ss}`;
    }
    return `${mm}:${ss}`;
}

export function formatTime(iso: string): string {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return '--:--';
    }
    return CAIRO_TIME.format(date);
}

export function formatClock(nowMs: number): string {
    return CAIRO_CLOCK.format(new Date(nowMs));
}

export function isBoardTicket(ticket: KdsTicket): boolean {
    if (ticket.status === 'active') {
        return true;
    }
    return ticket.status === 'cancelled' && ticket.cancel_acknowledged_at == null;
}

function openRound(ticket: KdsTicket): KdsRound | undefined {
    return ticket.rounds.find((round) => round.ended_at == null);
}

function sortMillis(ticket: KdsTicket): number {
    const iso = openRound(ticket)?.started_at ?? ticket.first_sent_at;
    const ms = new Date(iso).getTime();
    return Number.isNaN(ms) ? 0 : ms;
}

export function sortBoardTickets(tickets: KdsTicket[]): KdsTicket[] {
    return [...tickets].sort((a, b) => {
        if (a.is_rush !== b.is_rush) {
            return a.is_rush ? -1 : 1;
        }
        const diff = sortMillis(a) - sortMillis(b);
        if (diff !== 0) {
            return diff;
        }
        return a.order_number - b.order_number || a.id - b.id;
    });
}

export function sortRecallTickets(tickets: KdsTicket[]): KdsTicket[] {
    return tickets
        .filter((ticket) => ticket.status === 'done')
        .sort((a, b) => {
            const aTime = a.done_at ? new Date(a.done_at).getTime() : 0;
            const bTime = b.done_at ? new Date(b.done_at).getTime() : 0;
            return bTime - aTime || b.id - a.id;
        });
}

export function applyStationFilter(tickets: KdsTicket[], stationId: number | null): KdsTicket[] {
    if (stationId == null) {
        return tickets;
    }
    const filtered: KdsTicket[] = [];
    for (const ticket of tickets) {
        const items = ticket.items.filter((item) => item.station_id === stationId);
        if (items.length === 0) {
            continue;
        }
        const openItems = items.filter((item) => item.status === 'pending' || item.status === 'started');
        const round = openRound(ticket);
        const roundTargets = openItems
            .filter((item) => round != null && item.round_id === round.id)
            .map((item) => item.target_minutes);
        const stationTarget = roundTargets.length > 0 ? Math.max(...roundTargets) : null;

        // When this station has finished its part, the ticket is done for this screen
        // (it moves to recall) even if other stations are still working on it.
        if (ticket.status === 'active' && openItems.length === 0) {
            const doneTimes = items
                .map((item) => (item.done_at ? new Date(item.done_at).getTime() : 0))
                .filter((time) => time > 0);
            const doneAt = doneTimes.length > 0 ? new Date(Math.max(...doneTimes)).toISOString() : null;
            filtered.push({ ...ticket, items, status: 'done', done_at: doneAt, open_round_target_minutes: null });
            continue;
        }

        filtered.push({ ...ticket, items, open_round_target_minutes: stationTarget });
    }
    return filtered;
}

export function stationLabel(stationId: number | null, stations: KdsStation[]): string {
    if (stationId == null) {
        return 'كل المحطات';
    }
    return stations.find((station) => station.id === stationId)?.name ?? 'كل المحطات';
}

export function openRoundElapsedSeconds(ticket: KdsTicket, nowMs: number): number | null {
    const round = openRound(ticket);
    if (!round) {
        return null;
    }
    const start = new Date(round.started_at).getTime();
    if (Number.isNaN(start)) {
        return null;
    }
    return Math.max(0, Math.floor((nowMs - start) / 1000));
}

export function kitchenElapsedSeconds(ticket: KdsTicket, nowMs: number): number {
    const openElapsed = openRoundElapsedSeconds(ticket, nowMs) ?? 0;
    return Math.max(0, ticket.closed_rounds_seconds) + openElapsed;
}

export function roundElapsedSeconds(round: KdsRound, nowMs: number): number {
    const start = new Date(round.started_at).getTime();
    const end = round.ended_at ? new Date(round.ended_at).getTime() : nowMs;
    if (Number.isNaN(start) || Number.isNaN(end)) {
        return 0;
    }
    return Math.max(0, Math.floor((end - start) / 1000));
}

export function ticketUrgency(
    ticket: KdsTicket,
    nowMs: number,
    warningPercent: number,
): TicketUrgency {
    if (ticket.status === 'cancelled' || ticket.open_round_target_minutes == null) {
        return 'normal';
    }
    const elapsed = openRoundElapsedSeconds(ticket, nowMs);
    if (elapsed == null) {
        return 'normal';
    }
    const targetSeconds = ticket.open_round_target_minutes * 60;
    if (targetSeconds <= 0) {
        return elapsed > 0 ? 'late' : 'normal';
    }
    if (elapsed > targetSeconds) {
        return 'late';
    }
    if (elapsed >= targetSeconds * (warningPercent / 100)) {
        return 'warning';
    }
    return 'normal';
}

export function boardCounts(
    tickets: KdsTicket[],
    nowMs: number,
    warningPercent: number,
): BoardCounts {
    const counts: BoardCounts = { fresh: 0, warning: 0, late: 0 };
    for (const ticket of tickets) {
        if (ticket.status === 'cancelled') {
            continue;
        }
        if (ticket.seen_at == null) {
            counts.fresh += 1;
        }
        const urgency = ticketUrgency(ticket, nowMs, warningPercent);
        if (urgency === 'warning') {
            counts.warning += 1;
        }
        if (urgency === 'late') {
            counts.late += 1;
        }
    }
    return counts;
}

export function ticketNeedsAttention(ticket: KdsTicket): boolean {
    if (ticket.status === 'cancelled' && ticket.cancel_acknowledged_at == null) {
        return true;
    }
    if (ticket.seen_at == null) {
        return true;
    }
    return ticket.items.some((item) => item.needs_ack);
}

export function ticketPlaceLabel(ticket: KdsTicket): string | null {
    if (ticket.table_number) {
        return `طاولة ${ticket.table_number}`;
    }
    if (ticket.customer_name) {
        return ticket.customer_name;
    }
    return null;
}

export function groupItemsByRound(ticket: KdsTicket): RoundGroup[] {
    const byRound = new Map<number, KdsItem[]>();
    for (const item of ticket.items) {
        const list = byRound.get(item.round_id) ?? [];
        list.push(item);
        byRound.set(item.round_id, list);
    }

    const groups: RoundGroup[] = [];
    const rounds = [...ticket.rounds].sort((a, b) => a.number - b.number);
    for (const round of rounds) {
        const items = byRound.get(round.id);
        if (!items || items.length === 0) {
            continue;
        }
        groups.push({ round, items });
        byRound.delete(round.id);
    }

    for (const [roundId, items] of byRound) {
        groups.push({
            round: {
                id: roundId,
                number: 0,
                started_at: ticket.first_sent_at,
                ended_at: null,
            },
            items,
        });
    }

    return groups;
}

export function ticketBlockKeys(ticket: KdsTicket): string[] {
    const keys = ['header'];
    if (ticket.status === 'cancelled') {
        keys.push('cancelled');
    }
    if (ticket.kitchen_notes) {
        keys.push('notes');
    }
    for (const group of groupItemsByRound(ticket)) {
        if (group.round.number > 1) {
            keys.push(`round-${group.round.id}`);
        }
        for (const item of group.items) {
            keys.push(`line-${item.id}`);
        }
    }
    keys.push('footer');
    return keys;
}

export function allDayCounts(tickets: KdsTicket[]): AllDayRow[] {
    const totals = new Map<string, number>();
    for (const ticket of tickets) {
        if (ticket.status === 'cancelled') {
            continue;
        }
        for (const item of ticket.items) {
            if (item.status !== 'pending' && item.status !== 'started') {
                continue;
            }
            const name = item.product_name || 'بدون اسم';
            totals.set(name, (totals.get(name) ?? 0) + Number(item.quantity));
        }
    }
    return [...totals.entries()]
        .map(([name, quantity]) => ({ name, quantity }))
        .sort((a, b) => b.quantity - a.quantity || a.name.localeCompare(b.name, 'ar'));
}

export function canUndoItem(item: KdsItem, recallMinutes: number, nowMs: number): boolean {
    if (item.status !== 'done' || item.done_at == null) {
        return false;
    }
    const doneAt = new Date(item.done_at).getTime();
    if (Number.isNaN(doneAt)) {
        return false;
    }
    return nowMs - doneAt <= recallMinutes * 60_000;
}

export function lineAction(
    ticket: KdsTicket,
    item: KdsItem,
    settings: KdsSettings,
    nowMs: number,
): LineAction | null {
    if (ticket.status === 'cancelled' || item.status === 'voided') {
        return null;
    }
    if (item.status === 'done') {
        return canUndoItem(item, settings.recall_minutes, nowMs) ? 'undo' : null;
    }
    if (ticket.status !== 'active' || !settings.item_tracking) {
        return null;
    }
    if (item.status === 'pending' && settings.require_start) {
        return 'start';
    }
    if (item.status === 'pending' || item.status === 'started') {
        return 'done';
    }
    return null;
}

export function findTicket(tickets: KdsTicket[], query: string): KdsTicket | undefined {
    const normalized = normalizeDigits(query).trim();
    if (!normalized) {
        return undefined;
    }
    const exact = tickets.find((ticket) => String(ticket.order_number) === normalized);
    if (exact) {
        return exact;
    }
    return tickets.find((ticket) => String(ticket.order_number).includes(normalized));
}

export function measureKey(ticketId: number, blockKey: string): string {
    return `${ticketId}:${blockKey}`;
}
