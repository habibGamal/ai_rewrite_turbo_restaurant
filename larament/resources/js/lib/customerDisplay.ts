/**
 * Customer-facing display (second monitor on the cashier PC).
 *
 * The cashier screen publishes what the customer should see; the display page,
 * opened in another window of the same browser, listens. BroadcastChannel carries
 * live updates and localStorage keeps the last state for a display opened later.
 * Nothing goes through the server.
 */

export interface CustomerDisplayLine {
    name: string;
    quantity: number;
    price: number;
    total: number;
}

export type CustomerDisplayState =
    | { status: "idle"; updatedAt: number }
    | {
          status: "cart";
          lines: CustomerDisplayLine[];
          subTotal: number;
          discount: number;
          total: number;
          updatedAt: number;
      }
    | {
          status: "paid";
          orderNumber: number;
          total: number;
          paid: number;
          change: number;
          updatedAt: number;
      };

type Unstamped<T> = T extends unknown ? Omit<T, "updatedAt"> : never;

const CHANNEL = "pos-customer-display";
const STORAGE_KEY = "pos-customer-display:state";

const openChannel = (): BroadcastChannel | null => {
    try {
        return typeof BroadcastChannel !== "undefined" ? new BroadcastChannel(CHANNEL) : null;
    } catch {
        return null;
    }
};

let sender: BroadcastChannel | null = null;
let lastPublished = "";

export const publishCustomerDisplay = (state: Unstamped<CustomerDisplayState>): void => {
    const serialized = JSON.stringify(state);
    if (serialized === lastPublished) return;
    lastPublished = serialized;

    const stamped = { ...state, updatedAt: Date.now() } as CustomerDisplayState;
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(stamped));
    } catch {
        // storage full or disabled: live updates still go through the channel
    }

    sender ??= openChannel();
    sender?.postMessage(stamped);
};

export const readCustomerDisplay = (): CustomerDisplayState => {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (raw) return JSON.parse(raw) as CustomerDisplayState;
    } catch {
        // fall through
    }

    return { status: "idle", updatedAt: Date.now() };
};

/** Calls `onChange` with every new state; returns the unsubscribe function. */
export const subscribeCustomerDisplay = (onChange: (state: CustomerDisplayState) => void): (() => void) => {
    const channel = openChannel();
    const onMessage = (event: MessageEvent<CustomerDisplayState>) => onChange(event.data);
    channel?.addEventListener("message", onMessage);

    // Fallback for browsers without BroadcastChannel: the storage event fires in other windows.
    const onStorage = (event: StorageEvent) => {
        if (event.key === STORAGE_KEY && event.newValue) {
            try {
                onChange(JSON.parse(event.newValue) as CustomerDisplayState);
            } catch {
                // ignore malformed state
            }
        }
    };
    if (!channel) window.addEventListener("storage", onStorage);

    return () => {
        channel?.removeEventListener("message", onMessage);
        channel?.close();
        window.removeEventListener("storage", onStorage);
    };
};
