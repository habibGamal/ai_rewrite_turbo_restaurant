import { playKdsSound } from '@/Components/Kitchen/sounds';
import type { KdsBroadcastPayload, KdsSettings, KdsSound, KdsTicket } from '@/types/kitchen';
import { useCallback, useEffect, useRef, useState } from 'react';

interface WakeLockSentinelLike {
    release: () => Promise<void>;
}

function requestWakeLock(): Promise<WakeLockSentinelLike | null> {
    const nav = navigator as Navigator & {
        wakeLock?: { request: (type: 'screen') => Promise<WakeLockSentinelLike> };
    };
    if (!nav.wakeLock) {
        return Promise.resolve(null);
    }
    return nav.wakeLock.request('screen').catch(() => null);
}

export function useKdsSounds(options: {
    settings: KdsSettings;
    muted: boolean;
    tickets: KdsTicket[];
    attentionTickets: KdsTicket[];
}): {
    needsUnlock: boolean;
    unlock: () => void;
    play: (sound: KdsSound, force?: boolean) => void;
    playPayload: (payload: KdsBroadcastPayload) => void;
} {
    const { settings, muted, tickets, attentionTickets } = options;
    const [unlocked, setUnlocked] = useState(false);
    const unlockedRef = useRef(false);
    const mutedRef = useRef(muted);
    const settingsRef = useRef(settings);
    const ctxRef = useRef<AudioContext | null>(null);
    const queuedRef = useRef<KdsSound | null>(null);
    const knownItemIds = useRef<Set<number> | null>(null);
    const knownTicketIds = useRef<Set<number> | null>(null);
    const sentinelRef = useRef<WakeLockSentinelLike | null>(null);

    mutedRef.current = muted;
    settingsRef.current = settings;

    const getCtx = useCallback((): AudioContext => {
        if (!ctxRef.current) {
            ctxRef.current = new AudioContext();
        }
        return ctxRef.current;
    }, []);

    const playNow = useCallback(
        (sound: KdsSound, volume: number) => {
            const ctx = getCtx();
            if (ctx.state === 'suspended') {
                void ctx.resume();
            }
            playKdsSound(ctx, sound, volume);
        },
        [getCtx],
    );

    const play = useCallback(
        (sound: KdsSound, force = false) => {
            if (sound === 'none') {
                return;
            }
            if (!force && mutedRef.current) {
                return;
            }
            if (!force && !unlockedRef.current) {
                queuedRef.current = sound;
                return;
            }
            const volume = force
                ? Math.max(settingsRef.current.sound_volume, 40)
                : settingsRef.current.sound_volume;
            playNow(sound, volume);
        },
        [playNow],
    );

    // Broadcast events trigger a reload; sound decisions run once the reloaded
    // items arrive so non-item updates (done, undo, rush, ack, cancel, reductions) stay silent.
    const playPayload = useCallback((_payload: KdsBroadcastPayload) => {}, []);

    const unlock = useCallback(() => {
        unlockedRef.current = true;
        setUnlocked(true);
        const ctx = getCtx();
        void ctx.resume().catch(() => undefined);
        void requestWakeLock().then((sentinel) => {
            sentinelRef.current = sentinel;
        });
        const queued = queuedRef.current;
        queuedRef.current = null;
        if (queued) {
            playNow(queued, settingsRef.current.sound_volume);
        }
    }, [getCtx, playNow]);

    useEffect(() => {
        if (!unlocked) {
            return;
        }
        const onVisible = (): void => {
            if (document.visibilityState === 'visible') {
                void requestWakeLock().then((sentinel) => {
                    sentinelRef.current = sentinel;
                });
            }
        };
        document.addEventListener('visibilitychange', onVisible);
        return () => document.removeEventListener('visibilitychange', onVisible);
    }, [unlocked]);

    useEffect(() => {
        const previousItems = knownItemIds.current;
        const previousTickets = knownTicketIds.current;

        if (previousItems == null || previousTickets == null) {
            const initialItems = new Set<number>();
            const initialTickets = new Set<number>();
            for (const ticket of tickets) {
                initialTickets.add(ticket.id);
                for (const item of ticket.items) {
                    initialItems.add(item.id);
                }
            }
            knownItemIds.current = initialItems;
            knownTicketIds.current = initialTickets;
            return;
        }

        let hasNewTicketItems = false;
        let hasAddedTicketItems = false;

        for (const ticket of attentionTickets) {
            if (ticket.status !== 'active') {
                continue;
            }
            const hasNewPendingItem = ticket.items.some(
                (item) => item.status === 'pending' && !previousItems.has(item.id),
            );
            if (!hasNewPendingItem) {
                continue;
            }
            if (!previousTickets.has(ticket.id)) {
                hasNewTicketItems = true;
            } else {
                hasAddedTicketItems = true;
            }
        }

        for (const ticket of tickets) {
            previousTickets.add(ticket.id);
            for (const item of ticket.items) {
                previousItems.add(item.id);
            }
        }

        if (hasNewTicketItems) {
            play(settings.new_order_sound);
        } else if (hasAddedTicketItems) {
            const sound = settings.change_sound !== 'none' ? settings.change_sound : settings.new_order_sound;
            play(sound);
        }
    }, [tickets, attentionTickets, play, settings.change_sound, settings.new_order_sound]);

    useEffect(() => {
        return () => {
            void sentinelRef.current?.release();
            void ctxRef.current?.close();
        };
    }, []);

    return { needsUnlock: !unlocked, unlock, play, playPayload };
}
