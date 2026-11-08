import { soundForReason } from '@/Components/Kitchen/kitchenActions';
import { playKdsSound } from '@/Components/Kitchen/sounds';
import { ticketNeedsAttention } from '@/Components/Kitchen/ticketModel';
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
    const attentionRef = useRef(attentionTickets);
    const ctxRef = useRef<AudioContext | null>(null);
    const queuedRef = useRef<KdsSound | null>(null);
    const knownIds = useRef<Set<number> | null>(null);
    const announced = useRef<Set<number>>(new Set());
    const sentinelRef = useRef<WakeLockSentinelLike | null>(null);

    mutedRef.current = muted;
    settingsRef.current = settings;
    attentionRef.current = attentionTickets;

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

    const playPayload = useCallback(
        (payload: KdsBroadcastPayload) => {
            announced.current.add(payload.ticket_id);
            play(soundForReason(payload.reason, settingsRef.current));
        },
        [play],
    );

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
        const previous = knownIds.current;
        const next = new Set(tickets.map((ticket) => ticket.id));
        if (previous == null) {
            knownIds.current = next;
            return;
        }
        const attentionIds = new Set(attentionTickets.map((ticket) => ticket.id));
        for (const ticket of tickets) {
            if (previous.has(ticket.id) || announced.current.has(ticket.id)) {
                continue;
            }
            if (!attentionIds.has(ticket.id)) {
                continue;
            }
            if (ticket.status === 'cancelled') {
                play(settings.cancel_sound);
            } else if (ticket.status === 'active') {
                play(settings.new_order_sound);
            }
            announced.current.add(ticket.id);
        }
        knownIds.current = next;
    }, [tickets, attentionTickets, play, settings.cancel_sound, settings.new_order_sound]);

    useEffect(() => {
        if (!unlocked || muted || settings.reminder_seconds <= 0) {
            return;
        }
        const id = window.setInterval(() => {
            if (mutedRef.current) {
                return;
            }
            const waiting = attentionRef.current.some(ticketNeedsAttention);
            if (!waiting) {
                return;
            }
            play(settingsRef.current.new_order_sound);
        }, settings.reminder_seconds * 1000);
        return () => window.clearInterval(id);
    }, [unlocked, muted, settings.reminder_seconds, play]);

    useEffect(() => {
        return () => {
            void sentinelRef.current?.release();
            void ctxRef.current?.close();
        };
    }, []);

    return { needsUnlock: !unlocked, unlock, play, playPayload };
}
