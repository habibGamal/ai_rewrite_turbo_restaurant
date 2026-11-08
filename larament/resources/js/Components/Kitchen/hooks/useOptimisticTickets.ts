import { kitchenUrls, postKitchen } from '@/Components/Kitchen/kitchenActions';
import {
    isoFromMs,
    isShownOnBoard,
    kitchenErrorMessage,
    mergeOptimisticTickets,
    patchIsReflected,
    type OptimisticPatch,
} from '@/Components/Kitchen/optimisticTickets';
import { ticketNeedsAttention } from '@/Components/Kitchen/ticketModel';
import type { KdsItem, KdsTicket } from '@/types/kitchen';
import { usePage } from '@inertiajs/react';
import { App as AntApp } from 'antd';
import { useEffect, useMemo, useRef, useState } from 'react';

interface TrackedPatch {
    id: string;
    patch: OptimisticPatch;
    resolved: boolean;
    /** Server tickets reference observed when the request succeeded. */
    serverAtResolve: KdsTicket[] | null;
}

export interface OptimisticActions {
    tickets: KdsTicket[];
    applyItem: (ticket: KdsTicket, item: KdsItem, action: 'start' | 'done' | 'undo') => void;
    finishTicket: (ticket: KdsTicket) => void;
    acknowledge: (ticket: KdsTicket) => void;
    toggleRush: (ticket: KdsTicket) => void;
}

/**
 * Pending patches sit over the server `tickets` prop. A reload that still has
 * the old line does not flicker it back: the patch wins until the server
 * reflects it, or the request succeeds and a newer props snapshot arrives.
 * Repeated taps on an in-flight item are ignored; other items stay free.
 */
export function useOptimisticTickets(options: {
    tickets: KdsTicket[];
    now: () => number;
    stationId: number | null;
    onLeaving?: (ticketId: number) => void;
}): OptimisticActions {
    const { tickets, now, stationId, onLeaving } = options;
    const { message } = AntApp.useApp();
    const page = usePage();
    const [patches, setPatches] = useState<TrackedPatch[]>([]);
    const [epoch, setEpoch] = useState(0);

    const ticketsRef = useRef(tickets);
    const patchesRef = useRef(patches);
    const stationRef = useRef(stationId);
    const onLeavingRef = useRef(onLeaving);
    const nowRef = useRef(now);
    const pageRef = useRef(page);
    const messageRef = useRef(message);
    const seqRef = useRef(0);

    ticketsRef.current = tickets;
    patchesRef.current = patches;
    stationRef.current = stationId;
    onLeavingRef.current = onLeaving;
    nowRef.current = now;
    pageRef.current = page;
    messageRef.current = message;

    const merged = useMemo(
        () => mergeOptimisticTickets(tickets, patches.map((entry) => entry.patch)),
        [tickets, patches],
    );

    useEffect(() => {
        setPatches((current) => {
            const next = current.filter((entry) => {
                if (patchIsReflected(tickets, entry.patch)) {
                    return false;
                }
                if (entry.resolved && entry.serverAtResolve !== tickets) {
                    return false;
                }
                return true;
            });
            if (next.length === current.length) {
                return current;
            }
            patchesRef.current = next;
            return next;
        });
    }, [tickets, epoch]);

    const itemBusy = (itemId: number): boolean =>
        patchesRef.current.some((entry) => {
            const patch = entry.patch;
            if (patch.kind === 'item' && patch.itemId === itemId) {
                return true;
            }
            return patch.kind === 'ticket-done' && patch.itemIds.includes(itemId);
        });

    const ticketBusy = (ticketId: number, kind: 'ticket-done' | 'ack' | 'rush'): boolean =>
        patchesRef.current.some((entry) => entry.patch.kind === kind && entry.patch.ticketId === ticketId);

    const commit = (patch: OptimisticPatch, ticketId: number): void => {
        const before = mergeOptimisticTickets(
            ticketsRef.current,
            patchesRef.current.map((entry) => entry.patch),
        );
        const tracked: TrackedPatch = {
            id: `${patch.kind}-${ticketId}-${(seqRef.current += 1)}`,
            patch,
            resolved: false,
            serverAtResolve: null,
        };
        const nextPatches = [...patchesRef.current, tracked];
        const after = mergeOptimisticTickets(
            ticketsRef.current,
            nextPatches.map((entry) => entry.patch),
        );
        if (
            isShownOnBoard(before, ticketId, stationRef.current) &&
            !isShownOnBoard(after, ticketId, stationRef.current)
        ) {
            try {
                onLeavingRef.current?.(ticketId);
            } catch {
                // The fade is cosmetic; the board still updates.
            }
        }
        patchesRef.current = nextPatches;
        setPatches(nextPatches);
        send(tracked.id, patch);
    };

    const send = (id: string, patch: OptimisticPatch): void => {
        let outcome: 'pending' | 'ok' | 'err' = 'pending';
        const drop = (): void => {
            const next = patchesRef.current.filter((entry) => entry.id !== id);
            patchesRef.current = next;
            setPatches(next);
        };
        const succeed = (): void => {
            outcome = 'ok';
            const snapshot = ticketsRef.current;
            setPatches((current) => {
                const next = current.map((entry) =>
                    entry.id === id ? { ...entry, resolved: true, serverAtResolve: snapshot } : entry,
                );
                patchesRef.current = next;
                return next;
            });
            setEpoch((value) => value + 1);
        };
        const fail = (errors?: Record<string, string>): void => {
            outcome = 'err';
            drop();
            const pageErrors = pageRef.current.props.errors as Record<string, unknown>;
            messageRef.current.error(kitchenErrorMessage(errors, pageErrors));
        };

        const handlers = {
            onSuccess: succeed,
            onError: fail,
            onFinish: () => {
                if (outcome === 'pending') {
                    drop();
                    messageRef.current.error('تعذر تنفيذ الإجراء');
                }
            },
        };

        if (patch.kind === 'item') {
            const url =
                patch.reopen
                    ? kitchenUrls.undo(patch.itemId)
                    : patch.status === 'started'
                      ? kitchenUrls.start(patch.itemId)
                      : kitchenUrls.done(patch.itemId);
            postKitchen(url, {}, handlers);
            return;
        }
        if (patch.kind === 'ticket-done') {
            postKitchen(
                kitchenUrls.ticketDone(patch.ticketId),
                patch.stationId == null ? {} : { station_id: patch.stationId },
                handlers,
            );
            return;
        }
        if (patch.kind === 'ack') {
            postKitchen(kitchenUrls.acknowledge(patch.ticketId), {}, handlers);
            return;
        }
        postKitchen(kitchenUrls.rush(patch.ticketId), { is_rush: patch.isRush }, handlers);
    };

    const applyItem = (ticket: KdsTicket, item: KdsItem, action: 'start' | 'done' | 'undo'): void => {
        if (itemBusy(item.id)) {
            return;
        }
        const at = isoFromMs(nowRef.current());
        if (action === 'start') {
            commit(
                {
                    kind: 'item',
                    ticketId: ticket.id,
                    itemId: item.id,
                    status: 'started',
                    started_at: at,
                    done_at: null,
                    reopen: false,
                },
                ticket.id,
            );
            return;
        }
        if (action === 'done') {
            commit(
                {
                    kind: 'item',
                    ticketId: ticket.id,
                    itemId: item.id,
                    status: 'done',
                    started_at: item.started_at,
                    done_at: at,
                    reopen: false,
                },
                ticket.id,
            );
            return;
        }
        commit(
            {
                kind: 'item',
                ticketId: ticket.id,
                itemId: item.id,
                status: item.started_at ? 'started' : 'pending',
                started_at: item.started_at,
                done_at: null,
                reopen: true,
            },
            ticket.id,
        );
    };

    const finishTicket = (ticket: KdsTicket): void => {
        if (ticketBusy(ticket.id, 'ticket-done')) {
            return;
        }
        const station = stationRef.current;
        const itemIds = ticket.items
            .filter(
                (item) =>
                    (item.status === 'pending' || item.status === 'started') &&
                    (station == null || item.station_id === station),
            )
            .map((item) => item.id);
        if (itemIds.length === 0) {
            return;
        }
        commit(
            {
                kind: 'ticket-done',
                ticketId: ticket.id,
                stationId: station,
                itemIds,
                doneAt: isoFromMs(nowRef.current()),
            },
            ticket.id,
        );
    };

    const acknowledge = (ticket: KdsTicket): void => {
        if (!ticketNeedsAttention(ticket)) {
            return;
        }
        if (ticketBusy(ticket.id, 'ack')) {
            return;
        }
        commit({ kind: 'ack', ticketId: ticket.id, at: isoFromMs(nowRef.current()) }, ticket.id);
    };

    const toggleRush = (ticket: KdsTicket): void => {
        if (ticketBusy(ticket.id, 'rush')) {
            return;
        }
        commit({ kind: 'rush', ticketId: ticket.id, isRush: !ticket.is_rush }, ticket.id);
    };

    return { tickets: merged, applyItem, finishTicket, acknowledge, toggleRush };
}
