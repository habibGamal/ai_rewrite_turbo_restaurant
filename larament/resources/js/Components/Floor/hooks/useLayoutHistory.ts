import { useCallback, useMemo, useState } from 'react';
import type { FloorLayout } from '@/types';
import { assignTableZones, normalizeLayout } from '@/Components/Floor/floorLayout';

export interface HistoryEntry {
    layout: FloorLayout;
    savedTableFlags: boolean[];
}

export interface UseLayoutHistoryResult {
    layout: FloorLayout;
    savedTableFlags: boolean[];
    isDirty: boolean;
    canUndo: boolean;
    canRedo: boolean;
    commitChange: (nextLayout: FloorLayout, nextSavedFlags?: boolean[]) => void;
    undo: () => void;
    redo: () => void;
    markSaved: (savedLayout: FloorLayout) => void;
}

export function useLayoutHistory(
    initialRawLayout: FloorLayout | null,
    onHistoryStep?: () => void,
): UseLayoutHistoryResult {
    const initialNormalized = useMemo(
        () => assignTableZones(normalizeLayout(initialRawLayout)),
        [initialRawLayout],
    );

    const [history, setHistory] = useState<HistoryEntry[]>(() => [
        {
            layout: initialNormalized,
            savedTableFlags: initialNormalized.tables.map(() => true),
        },
    ]);
    const [historyIndex, setHistoryIndex] = useState<number>(0);
    const [savedSnapshotJson, setSavedSnapshotJson] = useState<string>(() =>
        JSON.stringify(initialNormalized),
    );

    const currentEntry = history[historyIndex];
    const layout = useMemo(
        () => assignTableZones(currentEntry.layout),
        [currentEntry.layout],
    );
    const savedTableFlags = currentEntry.savedTableFlags;

    const isDirty = useMemo(
        () => JSON.stringify(layout) !== savedSnapshotJson,
        [layout, savedSnapshotJson],
    );

    const commitChange = useCallback(
        (nextLayout: FloorLayout, nextSavedFlags?: boolean[]) => {
            const zoned = assignTableZones(nextLayout);
            setHistory((prev) => {
                const sliced = prev.slice(0, historyIndex + 1);
                const flags =
                    nextSavedFlags ??
                    sliced[sliced.length - 1]?.savedTableFlags ??
                    zoned.tables.map(() => false);
                return [...sliced, { layout: zoned, savedTableFlags: flags }];
            });
            setHistoryIndex((prev) => prev + 1);
        },
        [historyIndex],
    );

    const canUndo = historyIndex > 0;
    const canRedo = historyIndex < history.length - 1;

    const undo = useCallback(() => {
        if (historyIndex > 0) {
            setHistoryIndex((prev) => prev - 1);
            onHistoryStep?.();
        }
    }, [historyIndex, onHistoryStep]);

    const redo = useCallback(() => {
        if (historyIndex < history.length - 1) {
            setHistoryIndex((prev) => prev + 1);
            onHistoryStep?.();
        }
    }, [history.length, historyIndex, onHistoryStep]);

    const markSaved = useCallback(
        (savedLayout: FloorLayout) => {
            const zoned = assignTableZones(savedLayout);
            setSavedSnapshotJson(JSON.stringify(zoned));
            setHistory((prev) =>
                prev.map((entry, idx) =>
                    idx === historyIndex
                        ? {
                              layout: zoned,
                              savedTableFlags: zoned.tables.map(() => true),
                          }
                        : entry,
                ),
            );
        },
        [historyIndex],
    );

    return {
        layout,
        savedTableFlags,
        isDirty,
        canUndo,
        canRedo,
        commitChange,
        undo,
        redo,
        markSaved,
    };
}
