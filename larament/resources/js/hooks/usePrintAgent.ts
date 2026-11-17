import { useCallback, useEffect, useRef, useState } from "react";

const AGENT = "http://127.0.0.1:47123";
const CHECK_EVERY_MS = 10_000;
const LAUNCH_AGAIN_AFTER_MS = 20_000;

async function agentRunning(): Promise<boolean> {
    try {
        const response = await fetch(`${AGENT}/status`, { cache: "no-store" });

        return response.ok;
    } catch {
        return false;
    }
}

/**
 * Keeps the Turbo Label app running on a drawer PC whose receipts print
 * through it (POS hosted away from the shop). The app is checked every few
 * seconds; while it doesn't answer, the cashier's next click anywhere opens
 * turbo-label://start (browsers only open apps from a click), so a receipt
 * queued on the server prints as soon as the app is up.
 *
 * @returns running: null while unknown; start(): open the app now.
 */
export function usePrintAgent(enabled: boolean) {
    const [running, setRunning] = useState<boolean | null>(null);
    const lastLaunch = useRef(0);

    const start = useCallback(() => {
        lastLaunch.current = Date.now();
        window.location.assign("turbo-label://start");
        // Check again shortly so the warning goes away once it's up.
        [1500, 3000, 6000].forEach((ms) =>
            setTimeout(() => agentRunning().then(setRunning), ms),
        );
    }, []);

    useEffect(() => {
        if (!enabled) {
            setRunning(null);
            return;
        }

        let cancelled = false;
        const check = () =>
            agentRunning().then((ok) => {
                if (!cancelled) {
                    setRunning(ok);
                }
            });

        check();
        const timer = window.setInterval(check, CHECK_EVERY_MS);

        return () => {
            cancelled = true;
            window.clearInterval(timer);
        };
    }, [enabled]);

    useEffect(() => {
        if (!enabled || running !== false) {
            return;
        }

        const onClick = () => {
            if (Date.now() - lastLaunch.current > LAUNCH_AGAIN_AFTER_MS) {
                start();
            }
        };

        document.addEventListener("pointerdown", onClick, true);

        return () => document.removeEventListener("pointerdown", onClick, true);
    }, [enabled, running, start]);

    return { running, start };
}
