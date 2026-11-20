import { useCallback, useEffect, useRef, useState } from 'react';

function offsetFrom(serverNow: string): number {
    const serverMs = new Date(serverNow).getTime();
    if (Number.isNaN(serverMs)) {
        return 0;
    }
    return serverMs - Date.now();
}

/** One 1-second ticker, corrected by the server clock offset from `serverNow`. */
export function useKdsClock(serverNow: string): { nowMs: number; now: () => number } {
    const offsetRef = useRef(offsetFrom(serverNow));
    const [tick, setTick] = useState(0);

    useEffect(() => {
        offsetRef.current = offsetFrom(serverNow);
        setTick((value) => value + 1);
    }, [serverNow]);

    useEffect(() => {
        const id = window.setInterval(() => setTick((value) => value + 1), 1000);
        return () => window.clearInterval(id);
    }, []);

    const now = useCallback(() => Date.now() + offsetRef.current, []);
    void tick;
    return { nowMs: now(), now };
}
