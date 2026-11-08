import { applyStationFilter, isBoardTicket } from '@/Components/Kitchen/ticketModel';
import type { KdsItem, KdsRound, KdsTicket } from '@/types/kitchen';

/**
 * Pure optimistic layer. Patches win over server tickets until `patchIsReflected`
 * is true (a poll/broadcast already has the change) or the caller drops them
 * after a successful reload. Concurrent patches on different items all apply.
 */

export type OptimisticPatch =
    | {
          kind: 'item';
          ticketId: number;
          itemId: number;
          status: 'pending' | 'started' | 'done';
          started_at: string | null;
          done_at: string | null;
          /** Undo: reopen the item's round and put the ticket back on the board. */
          reopen: boolean;
      }
    | {
          kind: 'ticket-done';
          ticketId: number;
          stationId: number | null;
          itemIds: number[];
          doneAt: string;
      }
    | {
          kind: 'ack';
          ticketId: number;
          at: string;
      }
    | {
          kind: 'rush';
          ticketId: number;
          isRush: boolean;
      };

export function patchTicketId(patch: OptimisticPatch): number {
    return patch.ticketId;
}

function isOpenStatus(status: KdsItem['status']): boolean {
    return status === 'pending' || status === 'started';
}

function findTicket(tickets: KdsTicket[], ticketId: number): KdsTicket | undefined {
    return tickets.find((ticket) => ticket.id === ticketId);
}

function findItem(ticket: KdsTicket, itemId: number): KdsItem | undefined {
    return ticket.items.find((item) => item.id === itemId);
}

export function patchIsReflected(tickets: KdsTicket[], patch: OptimisticPatch): boolean {
    const ticket = findTicket(tickets, patch.ticketId);
    if (!ticket) {
        // Left the payload (acknowledged cancel, or done past the recall window).
        return true;
    }

    if (patch.kind === 'item') {
        const item = findItem(ticket, patch.itemId);
        if (!item) {
            return true;
        }
        if (patch.reopen) {
            return item.status !== 'done' && item.done_at == null;
        }
        if (patch.status === 'done') {
            return item.status === 'done';
        }
        if (patch.status === 'started') {
            return item.status === 'started' || item.status === 'done';
        }
        return item.status === patch.status && item.done_at == null;
    }

    if (patch.kind === 'ticket-done') {
        return patch.itemIds.every((itemId) => {
            const item = findItem(ticket, itemId);
            return item == null || item.status === 'done';
        });
    }

    if (patch.kind === 'ack') {
        const seen = ticket.seen_at != null;
        const linesClear = ticket.items.every((item) => !item.needs_ack);
        const cancelClear = ticket.status !== 'cancelled' || ticket.cancel_acknowledged_at != null;
        return seen && linesClear && cancelClear;
    }

    return ticket.is_rush === patch.isRush;
}

function closedSeconds(rounds: KdsRound[]): number {
    let total = 0;
    for (const round of rounds) {
        if (!round.ended_at) {
            continue;
        }
        const start = new Date(round.started_at).getTime();
        const end = new Date(round.ended_at).getTime();
        if (Number.isNaN(start) || Number.isNaN(end)) {
            continue;
        }
        total += Math.max(0, Math.round((end - start) / 1000));
    }
    return total;
}

function openTarget(ticket: KdsTicket): number | null {
    const round = ticket.rounds.find((entry) => entry.ended_at == null);
    if (!round) {
        return null;
    }
    let max: number | null = null;
    for (const item of ticket.items) {
        if (item.round_id !== round.id || !isOpenStatus(item.status)) {
            continue;
        }
        max = max == null ? item.target_minutes : Math.max(max, item.target_minutes);
    }
    return max;
}

/** Close the open round and mark the ticket done only when nothing is still open. */
function settleIfIdle(ticket: KdsTicket, nowIso: string): KdsTicket {
    if (ticket.status === 'cancelled') {
        return { ...ticket, open_round_target_minutes: openTarget(ticket) };
    }
    const open = ticket.items.some((item) => isOpenStatus(item.status));
    if (open) {
        return {
            ...ticket,
            status: ticket.status === 'done' ? 'active' : ticket.status,
            done_at: ticket.status === 'done' ? null : ticket.done_at,
            open_round_target_minutes: openTarget(ticket),
        };
    }
    const rounds = ticket.rounds.map((round) =>
        round.ended_at == null ? { ...round, ended_at: nowIso } : round,
    );
    return {
        ...ticket,
        rounds,
        status: 'done',
        done_at: ticket.done_at ?? nowIso,
        open_round_target_minutes: null,
        closed_rounds_seconds: closedSeconds(rounds),
    };
}

function applyItem(ticket: KdsTicket, patch: Extract<OptimisticPatch, { kind: 'item' }>): KdsTicket {
    const current = findItem(ticket, patch.itemId);
    if (!current) {
        return ticket;
    }
    const items = ticket.items.map((item) =>
        item.id === patch.itemId
            ? {
                  ...item,
                  status: patch.status,
                  started_at: patch.started_at,
                  done_at: patch.done_at,
              }
            : item,
    );
    if (patch.reopen) {
        const rounds = ticket.rounds.map((round) =>
            round.id === current.round_id ? { ...round, ended_at: null } : round,
        );
        const reopened: KdsTicket = {
            ...ticket,
            items,
            rounds,
            status: 'active',
            done_at: null,
            auto_finished: false,
        };
        return {
            ...reopened,
            open_round_target_minutes: openTarget(reopened),
            closed_rounds_seconds: closedSeconds(rounds),
        };
    }
    return settleIfIdle({ ...ticket, items }, patch.done_at ?? new Date().toISOString());
}

function applyTicketDone(ticket: KdsTicket, patch: Extract<OptimisticPatch, { kind: 'ticket-done' }>): KdsTicket {
    const ids = new Set(patch.itemIds);
    const items = ticket.items.map((item) => {
        if (!ids.has(item.id) || !isOpenStatus(item.status)) {
            return item;
        }
        return { ...item, status: 'done' as const, done_at: patch.doneAt };
    });
    return settleIfIdle({ ...ticket, items }, patch.doneAt);
}

function applyAck(ticket: KdsTicket, patch: Extract<OptimisticPatch, { kind: 'ack' }>): KdsTicket {
    return {
        ...ticket,
        seen_at: ticket.seen_at ?? patch.at,
        cancel_acknowledged_at:
            ticket.status === 'cancelled' ? (ticket.cancel_acknowledged_at ?? patch.at) : ticket.cancel_acknowledged_at,
        items: ticket.items.map((item) => (item.needs_ack ? { ...item, needs_ack: false } : item)),
    };
}

function applyOne(ticket: KdsTicket, patch: OptimisticPatch): KdsTicket {
    if (patch.ticketId !== ticket.id) {
        return ticket;
    }
    if (patch.kind === 'item') {
        return applyItem(ticket, patch);
    }
    if (patch.kind === 'ticket-done') {
        return applyTicketDone(ticket, patch);
    }
    if (patch.kind === 'ack') {
        return applyAck(ticket, patch);
    }
    return { ...ticket, is_rush: patch.isRush };
}

export function mergeOptimisticTickets(tickets: KdsTicket[], patches: OptimisticPatch[]): KdsTicket[] {
    const active = patches.filter((patch) => !patchIsReflected(tickets, patch));
    if (active.length === 0) {
        return tickets;
    }
    return tickets.map((ticket) => active.reduce(applyOne, ticket));
}

export function isShownOnBoard(tickets: KdsTicket[], ticketId: number, stationId: number | null): boolean {
    const ticket = applyStationFilter(tickets, stationId).find((entry) => entry.id === ticketId);
    return ticket != null && isBoardTicket(ticket);
}

export function kitchenErrorMessage(
    errors: Record<string, unknown> | undefined,
    pageErrors?: Record<string, unknown>,
): string {
    const kitchen = errors?.kitchen ?? pageErrors?.kitchen;
    if (typeof kitchen === 'string' && kitchen.trim()) {
        return kitchen;
    }
    const bags = [errors, pageErrors];
    for (const bag of bags) {
        if (!bag) {
            continue;
        }
        for (const value of Object.values(bag)) {
            if (typeof value === 'string' && value.trim()) {
                return value;
            }
        }
    }
    return 'تعذر تنفيذ الإجراء';
}

export function isoFromMs(ms: number): string {
    return new Date(ms).toISOString();
}
