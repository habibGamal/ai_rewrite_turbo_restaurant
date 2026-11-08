import type { KdsBroadcastPayload } from '@/types/kitchen';
import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

const POLL_MS = 30_000;
const RELOAD_DEBOUNCE_MS = 300;

export type KitchenConnection = string;

interface PusherConnection {
    state: string;
    bind: (event: string, callback: (states: { current?: string }) => void) => void;
    unbind: (event: string, callback: (states: { current?: string }) => void) => void;
}

interface KitchenEcho {
    private: (name: string) => {
        listen: (event: string, callback: (payload: KdsBroadcastPayload) => void) => void;
    };
    leave: (name: string) => void;
    connector?: {
        pusher?: {
            connection?: PusherConnection;
        };
    };
}

function kitchenEcho(): KitchenEcho | null {
    if (typeof window.Echo === 'undefined' || !window.Echo) {
        return null;
    }
    return window.Echo as unknown as KitchenEcho;
}

export function isConnectionLost(state: KitchenConnection): boolean {
    return state !== 'connected' && state !== 'connecting' && state !== 'initialized';
}

export function connectionLabel(state: KitchenConnection): string {
    if (state === 'connected') {
        return 'متصل';
    }
    if (state === 'connecting' || state === 'initialized') {
        return 'يتصل';
    }
    return 'غير متصل';
}

function payloadMatchesStation(payload: KdsBroadcastPayload, stationId: number | null): boolean {
    if (stationId == null || payload.station_ids.length === 0) {
        return true;
    }
    return payload.station_ids.includes(stationId);
}

/**
 * Private `kitchen` channel + always-on 30s fallback poll.
 * Bursts of broadcasts collapse into one `only: ['tickets']` reload.
 * `router.reload` already preserves scroll and component state.
 */
export function useKitchenChannel(options: {
    stationId: number | null;
    onPayload: (payload: KdsBroadcastPayload) => void;
}): { connection: KitchenConnection } {
    const { stationId, onPayload } = options;
    const [connection, setConnection] = useState<KitchenConnection>('connecting');
    const stationRef = useRef(stationId);
    const onPayloadRef = useRef(onPayload);
    stationRef.current = stationId;
    onPayloadRef.current = onPayload;

    useEffect(() => {
        const echo = kitchenEcho();
        if (!echo) {
            setConnection('unavailable');
        }

        let reloadTimer: number | null = null;
        const scheduleReload = (): void => {
            if (reloadTimer != null) {
                window.clearTimeout(reloadTimer);
            }
            reloadTimer = window.setTimeout(() => {
                reloadTimer = null;
                router.reload({ only: ['tickets'] });
            }, RELOAD_DEBOUNCE_MS);
        };

        const poll = window.setInterval(() => {
            router.reload({ only: ['tickets'] });
        }, POLL_MS);

        let pusherConnection: PusherConnection | null = null;
        const onState = (states: { current?: string }): void => {
            if (states.current) {
                setConnection(states.current);
            }
        };

        if (echo) {
            try {
                echo.private('kitchen').listen('.KitchenTicketUpdated', (payload) => {
                    if (!payloadMatchesStation(payload, stationRef.current)) {
                        return;
                    }
                    onPayloadRef.current(payload);
                    scheduleReload();
                });
            } catch {
                setConnection('unavailable');
            }

            pusherConnection = echo.connector?.pusher?.connection ?? null;
            if (pusherConnection) {
                pusherConnection.bind('state_change', onState);
                if (pusherConnection.state) {
                    setConnection(pusherConnection.state);
                }
            } else {
                setConnection('unavailable');
            }
        }

        return () => {
            window.clearInterval(poll);
            if (reloadTimer != null) {
                window.clearTimeout(reloadTimer);
            }
            pusherConnection?.unbind('state_change', onState);
            try {
                echo?.leave('kitchen');
            } catch {
                // Channel was never joined.
            }
        };
    }, []);

    return { connection };
}
