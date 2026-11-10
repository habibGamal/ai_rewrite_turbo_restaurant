import type React from 'react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { message } from 'antd';
import type { FloorLayout, FloorRect, FloorSection } from '@/types';
import {
    clampRectToGrid,
    findSectionAtCell,
    formatTableLabel,
    isSectionPlacementValid,
    isTablePlacementValid,
    nextSectionColor,
    nextSectionName,
    nextTableNumber,
    rectFromCells,
    type Cell,
    type GhostPreview,
    type SelectionTarget,
    type ToolMode,
} from '@/Components/Floor/floorLayout';

export type DragInteraction =
    | { type: 'move_table'; index: number; grabOffset: Cell; targetRect: FloorRect; hasMoved: boolean }
    | { type: 'resize_table'; index: number; targetRect: FloorRect; hasMoved: boolean }
    | { type: 'move_section'; index: number; grabOffset: Cell; targetRect: FloorRect; hasMoved: boolean }
    | { type: 'resize_section'; index: number; targetRect: FloorRect; hasMoved: boolean }
    | { type: 'draw_section'; startCell: Cell; currentCell: Cell };

export interface UseFloorDragOptions {
    layout: FloorLayout;
    tool: ToolMode;
    numberPrefix: string;
    onAddTableAtCell: (cell: Cell) => void;
    onCommitLayout: (nextLayout: FloorLayout) => void;
    onSelect: (target: SelectionTarget | null) => void;
    onSwitchTool: (tool: ToolMode) => void;
}

export interface UseFloorDragResult {
    gridRef: React.RefObject<HTMLDivElement>;
    ghostPreview: GhostPreview | null;
    cancelDrag: () => void;
    clearHoverCell: () => void;
    handlePointerMoveOnGrid: (event: React.PointerEvent<HTMLDivElement>) => void;
    handleCellPointerDown: (cell: Cell, event: React.PointerEvent<HTMLDivElement>) => void;
    handleSectionPointerDown: (index: number, event: React.PointerEvent<HTMLDivElement>) => void;
    handleSectionResizePointerDown: (index: number, event: React.PointerEvent<HTMLDivElement>) => void;
    handleTablePointerDown: (index: number, event: React.PointerEvent<HTMLDivElement>) => void;
    handleTableResizePointerDown: (index: number, event: React.PointerEvent<HTMLDivElement>) => void;
}

export function useFloorDrag({
    layout,
    tool,
    numberPrefix,
    onAddTableAtCell,
    onCommitLayout,
    onSelect,
    onSwitchTool,
}: UseFloorDragOptions): UseFloorDragResult {
    const gridRef = useRef<HTMLDivElement>(null);
    const [dragState, setDragState] = useState<DragInteraction | null>(null);
    const [hoverCell, setHoverCell] = useState<Cell | null>(null);

    const cancelDrag = useCallback(() => setDragState(null), []);
    const clearHoverCell = useCallback(() => setHoverCell(null), []);

    const getCellFromPointer = useCallback(
        (clientX: number, clientY: number, clampToBounds: boolean): Cell | null => {
            const element = gridRef.current;
            if (!element) {
                return null;
            }
        const rect = element.getBoundingClientRect();
        if (rect.width <= 0 || rect.height <= 0) {
            return null;
        }
        const rawCol = Math.floor(((rect.right - clientX) / rect.width) * layout.columns);
        const rawRow = Math.floor(((clientY - rect.top) / rect.height) * layout.rows);
        if (!clampToBounds) {
            return { col: rawCol, row: rawRow };
        }
        return {
            col: Math.max(0, Math.min(layout.columns - 1, rawCol)),
            row: Math.max(0, Math.min(layout.rows - 1, rawRow)),
        };
        },
        [layout.columns, layout.rows],
    );

    const ghostPreview: GhostPreview | null = useMemo(() => {
        if (!dragState) {
            if (tool !== 'add_table' || !hoverCell) {
                return null;
            }
            const candidate: FloorRect = { col: hoverCell.col, row: hoverCell.row, colSpan: 1, rowSpan: 1 };
            const valid = isTablePlacementValid(layout, candidate);
            const targetNumber = nextTableNumber(layout.tables, numberPrefix);
            return {
                ...candidate,
                valid,
                kind: 'table',
                shape: 'square',
                label: valid
                    ? formatTableLabel({ number: targetNumber, capacity: 4 }, layout.tableLabel)
                    : 'خلية مشغولة',
            };
        }

        if (dragState.type === 'move_table' || dragState.type === 'resize_table') {
            const table = layout.tables[dragState.index];
            if (!table) {
                return null;
            }
            const valid = isTablePlacementValid(layout, dragState.targetRect, dragState.index);
            const clamped = clampRectToGrid(dragState.targetRect, layout.columns, layout.rows);
            const shape = table.shape ?? 'square';
            return {
                ...clamped,
                valid,
                kind: 'table',
                shape,
                label: formatTableLabel(table, layout.tableLabel),
            };
        }

        if (dragState.type === 'move_section' || dragState.type === 'resize_section') {
            const section = layout.sections[dragState.index];
            if (!section) {
                return null;
            }
            const valid = isSectionPlacementValid(layout, dragState.targetRect, dragState.index);
            const clamped = clampRectToGrid(dragState.targetRect, layout.columns, layout.rows);
            return { ...clamped, valid, kind: 'section', label: section.name };
        }

        const rect = rectFromCells(dragState.startCell, dragState.currentCell);
        const valid = isSectionPlacementValid(layout, rect);
        const clamped = clampRectToGrid(rect, layout.columns, layout.rows);
        const label = `${nextSectionName(layout.sections)} (${clamped.colSpan}×${clamped.rowSpan})`;
        return { ...clamped, valid, kind: 'section', label };
    }, [dragState, hoverCell, layout, numberPrefix, tool]);

    const updateDragFromClientCoords = useCallback(
        (clientX: number, clientY: number) => {
            if (!dragState) {
                return;
            }
            const unclampedCell = getCellFromPointer(clientX, clientY, false);
            if (!unclampedCell) {
                return;
            }

            if (dragState.type === 'draw_section') {
                setDragState({ ...dragState, currentCell: unclampedCell });
                return;
            }

            const isTable = dragState.type === 'move_table' || dragState.type === 'resize_table';
            const sourceItem = isTable
                ? layout.tables[dragState.index]
                : layout.sections[dragState.index];
            if (!sourceItem) {
                return;
            }

            if (dragState.type === 'move_table' || dragState.type === 'move_section') {
                const col = unclampedCell.col - dragState.grabOffset.col;
                const row = unclampedCell.row - dragState.grabOffset.row;
                const hasMoved = dragState.hasMoved || col !== sourceItem.col || row !== sourceItem.row;
                setDragState({
                    ...dragState,
                    hasMoved,
                    targetRect: { col, row, colSpan: sourceItem.colSpan, rowSpan: sourceItem.rowSpan },
                });
                return;
            }

            const colSpan = Math.max(1, unclampedCell.col - sourceItem.col + 1);
            const rowSpan = Math.max(1, unclampedCell.row - sourceItem.row + 1);
            const hasMoved =
                dragState.hasMoved || colSpan !== sourceItem.colSpan || rowSpan !== sourceItem.rowSpan;
            setDragState({
                ...dragState,
                hasMoved,
                targetRect: { col: sourceItem.col, row: sourceItem.row, colSpan, rowSpan },
            });
        },
        [dragState, getCellFromPointer, layout.sections, layout.tables],
    );

    const handlePointerMoveOnGrid = useCallback(
        (event: React.PointerEvent<HTMLDivElement>) => {
            setHoverCell(getCellFromPointer(event.clientX, event.clientY, true));
            updateDragFromClientCoords(event.clientX, event.clientY);
        },
        [getCellFromPointer, updateDragFromClientCoords],
    );

    const finalizeDrag = useCallback(() => {
        if (!dragState) {
            return;
        }
        const currentDrag = dragState;
        setDragState(null);

        if (currentDrag.type === 'draw_section') {
            const rect = rectFromCells(currentDrag.startCell, currentDrag.currentCell);
            if (!isSectionPlacementValid(layout, rect)) {
                message.warning('لا يمكن وضع قسم فوق قسم آخر أو خارج الشبكة');
                return;
            }
            const name = nextSectionName(layout.sections);
            const newSection: FloorSection = {
                name,
                zone: name,
                color: nextSectionColor(layout.sections),
                ...rect,
            };
            const nextSections = [...layout.sections, newSection];
            onCommitLayout({ ...layout, sections: nextSections });
            onSelect({ kind: 'section', index: nextSections.length - 1 });
            onSwitchTool('select');
            return;
        }

        if (!currentDrag.hasMoved) {
            return;
        }

        if (currentDrag.type === 'move_table' || currentDrag.type === 'resize_table') {
            if (!isTablePlacementValid(layout, currentDrag.targetRect, currentDrag.index)) {
                message.warning('موضع غير صالح: لا يمكن وضع طاولة فوق أخرى أو خارج حدود الشبكة');
                return;
            }
            const nextTables = layout.tables.map((table, idx) =>
                idx === currentDrag.index ? { ...table, ...currentDrag.targetRect } : table,
            );
            onCommitLayout({ ...layout, tables: nextTables });
            return;
        }

        if (!isSectionPlacementValid(layout, currentDrag.targetRect, currentDrag.index)) {
            message.warning('موضع غير صالح: لا يمكن تداخل الأقسام أو الخروج عن حدود الشبكة');
            return;
        }
        const nextSections = layout.sections.map((section, idx) =>
            idx === currentDrag.index ? { ...section, ...currentDrag.targetRect } : section,
        );
        onCommitLayout({ ...layout, sections: nextSections });
    }, [dragState, layout, onCommitLayout, onSelect, onSwitchTool]);

    useEffect(() => {
        if (!dragState) {
            return;
        }
        const handleMove = (e: PointerEvent) => updateDragFromClientCoords(e.clientX, e.clientY);
        const handleUp = () => finalizeDrag();
        window.addEventListener('pointermove', handleMove);
        window.addEventListener('pointerup', handleUp);
        return () => {
            window.removeEventListener('pointermove', handleMove);
            window.removeEventListener('pointerup', handleUp);
        };
    }, [dragState, finalizeDrag, updateDragFromClientCoords]);

    const startMoveItem = useCallback(
        (kind: 'table' | 'section', index: number, pointerCell: Cell) => {
            const item = kind === 'table' ? layout.tables[index] : layout.sections[index];
            if (!item) {
                return;
            }
            onSelect({ kind, index });
            setDragState({
                type: kind === 'table' ? 'move_table' : 'move_section',
                index,
                grabOffset: { col: pointerCell.col - item.col, row: pointerCell.row - item.row },
                targetRect: { col: item.col, row: item.row, colSpan: item.colSpan, rowSpan: item.rowSpan },
                hasMoved: false,
            });
        },
        [layout.sections, layout.tables, onSelect],
    );

    const startResizeItem = useCallback(
        (kind: 'table' | 'section', index: number) => {
            const item = kind === 'table' ? layout.tables[index] : layout.sections[index];
            if (!item) {
                return;
            }
            onSelect({ kind, index });
            setDragState({
                type: kind === 'table' ? 'resize_table' : 'resize_section',
                index,
                targetRect: { col: item.col, row: item.row, colSpan: item.colSpan, rowSpan: item.rowSpan },
                hasMoved: false,
            });
        },
        [layout.sections, layout.tables, onSelect],
    );

    const handleCellPointerDown = useCallback(
        (cell: Cell, event: React.PointerEvent<HTMLDivElement>) => {
            event.stopPropagation();
            if (tool === 'add_table') {
                onAddTableAtCell(cell);
                return;
            }
            if (tool === 'draw_section') {
                setDragState({ type: 'draw_section', startCell: cell, currentCell: cell });
                return;
            }
            const sectionIdx = findSectionAtCell(layout.sections, cell);
            if (sectionIdx !== null) {
                startMoveItem('section', sectionIdx, cell);
            } else {
                onSelect(null);
            }
        },
        [layout.sections, onAddTableAtCell, onSelect, startMoveItem, tool],
    );

    const handleSectionPointerDown = useCallback(
        (index: number, event: React.PointerEvent<HTMLDivElement>) => {
            event.stopPropagation();
            const cell = getCellFromPointer(event.clientX, event.clientY, true);
            if (tool === 'add_table') {
                if (cell) {
                    onAddTableAtCell(cell);
                }
                return;
            }
            if (tool === 'draw_section') {
                if (cell) {
                    setDragState({ type: 'draw_section', startCell: cell, currentCell: cell });
                }
                return;
            }
            const sec = layout.sections[index];
            if (sec) {
                startMoveItem('section', index, cell ?? { col: sec.col, row: sec.row });
            }
        },
        [getCellFromPointer, layout.sections, onAddTableAtCell, startMoveItem, tool],
    );

    const handleSectionResizePointerDown = useCallback(
        (index: number, event: React.PointerEvent<HTMLDivElement>) => {
            event.stopPropagation();
            startResizeItem('section', index);
        },
        [startResizeItem],
    );

    const handleTablePointerDown = useCallback(
        (index: number, event: React.PointerEvent<HTMLDivElement>) => {
            event.stopPropagation();
            if (tool === 'add_table') {
                message.warning('هذه الخلية مشغولة بطاولة بالفعل');
                return;
            }
            const cell = getCellFromPointer(event.clientX, event.clientY, true);
            if (tool === 'draw_section') {
                if (cell) {
                    setDragState({ type: 'draw_section', startCell: cell, currentCell: cell });
                }
                return;
            }
            const tbl = layout.tables[index];
            if (tbl) {
                startMoveItem('table', index, cell ?? { col: tbl.col, row: tbl.row });
            }
        },
        [getCellFromPointer, layout.tables, startMoveItem, tool],
    );

    const handleTableResizePointerDown = useCallback(
        (index: number, event: React.PointerEvent<HTMLDivElement>) => {
            event.stopPropagation();
            startResizeItem('table', index);
        },
        [startResizeItem],
    );

    return {
        gridRef,
        ghostPreview,
        cancelDrag,
        clearHoverCell,
        handlePointerMoveOnGrid,
        handleCellPointerDown,
        handleSectionPointerDown,
        handleSectionResizePointerDown,
        handleTablePointerDown,
        handleTableResizePointerDown,
    };
}
