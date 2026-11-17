import { useEffect, useRef } from "react";

type Options = {
    /** Max milliseconds between two keystrokes of the same scan. */
    maxDelay?: number;
    /** Shortest code accepted as a scan. */
    minLength?: number;
    /**
     * A burst this long fires even without a trailing Enter (scanners set up
     * without a suffix). Humans cannot type this many keys under maxDelay.
     */
    minLengthWithoutEnter?: number;
    enabled?: boolean;
};

/**
 * True when the key belongs to something the user is typing into: an input,
 * a textarea, a select, an editable element, or anything inside an open
 * dialog. Those keystrokes are never treated as a scan.
 */
export function isTypingTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) return false;
    const tag = target.tagName;
    if (tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT") return true;
    if (target.isContentEditable) return true;
    return target.closest('[role="dialog"], .ant-modal, .ant-drawer, .ant-select-dropdown, .ant-picker-dropdown') !== null;
}

function dialogIsOpen(): boolean {
    return (
        document.querySelector(
            '.ant-modal-wrap:not([style*="display: none"]) .ant-modal, .ant-drawer-open'
        ) !== null
    );
}

/**
 * Global barcode scanner listener. A scanner types a whole code in one fast
 * burst (normally ending with Enter); normal keyboard typing is slower, and
 * typing inside fields or dialogs is ignored entirely.
 */
export function useBarcodeScanner(
    onScan: (code: string) => void,
    {
        maxDelay = 50,
        minLength = 3,
        minLengthWithoutEnter = 8,
        enabled = true,
    }: Options = {}
) {
    const onScanRef = useRef(onScan);
    onScanRef.current = onScan;

    useEffect(() => {
        if (!enabled) return;

        let buffer = "";
        let last = 0;
        let idleTimer: number | undefined;

        const reset = () => {
            buffer = "";
            window.clearTimeout(idleTimer);
        };

        const fire = () => {
            const code = buffer;
            reset();
            onScanRef.current(code);
        };

        const listener = (e: KeyboardEvent) => {
            if (e.ctrlKey || e.metaKey || e.altKey) return;
            if (isTypingTarget(e.target) || dialogIsOpen()) {
                reset();
                return;
            }

            const now = performance.now();
            const gap = now - last;
            last = now;

            if (e.key === "Enter") {
                if (buffer.length >= minLength && gap <= maxDelay) {
                    e.preventDefault();
                    fire();
                } else {
                    reset();
                }
                return;
            }

            if (e.key.length !== 1) return;

            // A slow key starts a new burst: whatever came before was typing.
            if (gap > maxDelay) buffer = "";
            buffer += e.key;

            window.clearTimeout(idleTimer);
            idleTimer = window.setTimeout(() => {
                if (buffer.length >= minLengthWithoutEnter) {
                    fire();
                } else {
                    reset();
                }
            }, maxDelay * 2);
        };

        window.addEventListener("keydown", listener, true);
        return () => {
            window.removeEventListener("keydown", listener, true);
            window.clearTimeout(idleTimer);
        };
    }, [enabled, maxDelay, minLength, minLengthWithoutEnter]);
}
