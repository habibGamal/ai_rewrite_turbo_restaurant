/**
 * Instant sale keyboard map: the single place that binds keys to cashier actions.
 * Change a key here and the button hints follow.
 */
export type InstantAction =
    | 'focusSearch'
    | 'discount'
    | 'customer'
    | 'hold'
    | 'heldList'
    | 'returns'
    | 'pay'
    | 'clearCart'
    | 'incrementLine'
    | 'decrementLine'
    | 'selectPrev'
    | 'selectNext'
    | 'deleteLine';

export const INSTANT_KEYMAP: Record<InstantAction, string> = {
    focusSearch: 'F2',
    discount: 'F4',
    customer: 'F6',
    hold: 'F8',
    heldList: 'F9',
    returns: 'F10',
    pay: 'F12',
    clearCart: 'Escape',
    incrementLine: '+',
    decrementLine: '-',
    selectPrev: 'ArrowUp',
    selectNext: 'ArrowDown',
    deleteLine: 'Delete',
};

/** Pressing Space while the scan field is empty also pays. */
export const PAY_ON_EMPTY_SPACE = true;

/** `3*` typed before a code means quantity 3. */
export const QUANTITY_MULTIPLIER = '*';

/** Short label shown on buttons. */
export const keyLabel = (action: InstantAction): string => {
    const key = INSTANT_KEYMAP[action];
    if (key === 'Escape') return 'Esc';
    if (key === 'Delete') return 'Del';
    return key;
};

export const actionForKey = (key: string): InstantAction | null => {
    const entry = (Object.entries(INSTANT_KEYMAP) as [InstantAction, string][]).find(
        ([, bound]) => bound === key
    );
    return entry ? entry[0] : null;
};

export const isFunctionKey = (key: string): boolean => /^F\d{1,2}$/.test(key);

/** Parses `3*12345` into quantity 3 and code 12345. */
export const parseScan = (raw: string): { quantity: number; code: string } => {
    const value = raw.trim();
    const index = value.indexOf(QUANTITY_MULTIPLIER);
    if (index > 0) {
        const quantity = Number(value.slice(0, index));
        const code = value.slice(index + 1).trim();
        if (Number.isFinite(quantity) && quantity > 0) {
            return { quantity, code };
        }
    }
    return { quantity: 1, code: value };
};
