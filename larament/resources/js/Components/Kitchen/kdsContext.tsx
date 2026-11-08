import type { KdsItem, KdsSettings, KdsTicket } from '@/types/kitchen';
import { createContext, useContext, type ReactNode } from 'react';

export interface KdsContextValue {
    nowMs: number;
    scale: number;
    kdsSettings: KdsSettings;
    stationId: number | null;
    stationNames: ReadonlyMap<number, string>;
    highlightedId: number | null;
    tapItem: (ticket: KdsTicket, item: KdsItem) => void;
    finishTicket: (ticket: KdsTicket) => void;
    acknowledge: (ticket: KdsTicket) => void;
    toggleRush: (ticket: KdsTicket) => void;
}

const KdsContext = createContext<KdsContextValue | null>(null);

export function KdsProvider({ value, children }: { value: KdsContextValue; children: ReactNode }) {
    return <KdsContext.Provider value={value}>{children}</KdsContext.Provider>;
}

export function useKds(): KdsContextValue {
    const value = useContext(KdsContext);
    if (!value) {
        throw new Error('useKds must be used within KdsProvider');
    }
    return value;
}
