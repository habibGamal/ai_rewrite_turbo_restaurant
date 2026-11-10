import React, { useCallback, useMemo, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { message } from 'antd';
import CashierLayout from '@/Layouts/CashierLayout';
import type {
    FloorLayout,
    FloorSection,
    FloorTable,
    TableLabelConfig,
} from '@/types';
import {
    assignTableZones,
    checkGridResize,
    findTableAtCell,
    findZoneForCell,
    isSectionPlacementValid,
    isTablePlacementValid,
    nextTableNumber,
    resolveTableLabel,
    validateFloorLayout,
    type Cell,
    type SelectionTarget,
    type ToolMode,
} from '@/Components/Floor/floorLayout';
import { DesignerToolbar } from '@/Components/Floor/DesignerToolbar';
import { FloorCanvas } from '@/Components/Floor/FloorCanvas';
import { PropertiesPanel } from '@/Components/Floor/PropertiesPanel';
import { useDesignerShortcuts } from '@/Components/Floor/hooks/useDesignerShortcuts';
import { useFloorDrag } from '@/Components/Floor/hooks/useFloorDrag';
import { useLayoutHistory } from '@/Components/Floor/hooks/useLayoutHistory';
import { useUnsavedGuard } from '@/Components/Floor/hooks/useUnsavedGuard';

interface DesignerProps {
    code: string;
    label?: string;
    backUrl?: string;
    layout: FloorLayout | null;
}

export default function Designer({
    code,
    label,
    backUrl,
    layout: initialLayout,
}: DesignerProps) {
    const pageErrors = usePage().props.errors as
        | Record<string, string>
        | undefined;

    const [tool, setTool] = useState<ToolMode>('select');
    const [selection, setSelection] = useState<SelectionTarget | null>(null);
    const [zoom, setZoom] = useState<number>(100);
    const [numberPrefix, setNumberPrefix] = useState<string>('');
    const [serverErrors, setServerErrors] = useState<string[]>([]);
    const [isSaving, setIsSaving] = useState<boolean>(false);

    const {
        layout,
        savedTableFlags,
        isDirty,
        canUndo,
        canRedo,
        commitChange,
        undo,
        redo,
        markSaved,
    } = useLayoutHistory(initialLayout, () => {
        setSelection(null);
    });

    const { isSavingRef, confirmLeaveIfDirty } = useUnsavedGuard(isDirty);

    const problems = useMemo(() => validateFloorLayout(layout), [layout]);

    const allServerErrorMessages = useMemo(() => {
        const fromPage = pageErrors ? Object.values(pageErrors) : [];
        return Array.from(new Set([...serverErrors, ...fromPage]));
    }, [pageErrors, serverErrors]);

    const handleAddTableAtCell = useCallback(
        (cell: Cell) => {
            const existingIndex = findTableAtCell(layout.tables, cell);
            if (existingIndex !== null) {
                message.warning('هذه الخلية مشغولة بطاولة أخرى');
                return;
            }

            const newNumber = nextTableNumber(layout.tables, numberPrefix);
            const zone = findZoneForCell(layout.sections, cell);
            const newTable: FloorTable = {
                number: newNumber,
                capacity: 4,
                shape: 'square',
                zone,
                active: true,
                col: cell.col,
                row: cell.row,
                colSpan: 1,
                rowSpan: 1,
            };

            const nextTables = [...layout.tables, newTable];
            const nextFlags = [...savedTableFlags, false];
            commitChange({ ...layout, tables: nextTables }, nextFlags);
            setSelection({ kind: 'table', index: nextTables.length - 1 });
        },
        [commitChange, layout, numberPrefix, savedTableFlags],
    );

    const {
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
    } = useFloorDrag({
        layout,
        tool,
        numberPrefix,
        onAddTableAtCell: handleAddTableAtCell,
        onCommitLayout: commitChange,
        onSelect: setSelection,
        onSwitchTool: setTool,
    });

    const handleUndo = useCallback(() => {
        cancelDrag();
        undo();
    }, [cancelDrag, undo]);

    const handleRedo = useCallback(() => {
        cancelDrag();
        redo();
    }, [cancelDrag, redo]);

    const updateTableLabel = useCallback(
        (changes: Partial<TableLabelConfig>) => {
            const nextTableLabel: TableLabelConfig = {
                ...resolveTableLabel(layout.tableLabel),
                ...changes,
            };
            commitChange({ ...layout, tableLabel: nextTableLabel });
        },
        [commitChange, layout],
    );

    const updateSelectedTable = useCallback(
        (changes: Partial<FloorTable>) => {
            if (!selection || selection.kind !== 'table') {
                return;
            }

            const currentTable = layout.tables[selection.index];
            if (!currentTable) {
                return;
            }

            const candidate: FloorTable = { ...currentTable, ...changes };
            const geometryChanged =
                changes.col !== undefined ||
                changes.row !== undefined ||
                changes.colSpan !== undefined ||
                changes.rowSpan !== undefined;

            if (
                geometryChanged &&
                !isTablePlacementValid(layout, candidate, selection.index)
            ) {
                message.warning(
                    'الحجم أو الموضع الجديد يتجاوز حدود الشبكة أو يتداخل مع طاولة أخرى',
                );
                return;
            }

            const nextTables = layout.tables.map((item, idx) =>
                idx === selection.index ? candidate : item,
            );
            commitChange({ ...layout, tables: nextTables });
        },
        [commitChange, layout, selection],
    );

    const updateSelectedSection = useCallback(
        (changes: Partial<FloorSection>) => {
            if (!selection || selection.kind !== 'section') {
                return;
            }

            const currentSection = layout.sections[selection.index];
            if (!currentSection) {
                return;
            }

            const candidate: FloorSection = { ...currentSection, ...changes };
            if (changes.name !== undefined) {
                candidate.zone = changes.name;
            }

            const geometryChanged =
                changes.col !== undefined ||
                changes.row !== undefined ||
                changes.colSpan !== undefined ||
                changes.rowSpan !== undefined;

            if (
                geometryChanged &&
                !isSectionPlacementValid(layout, candidate, selection.index)
            ) {
                message.warning(
                    'الحجم أو الموضع الجديد يتجاوز حدود الشبكة أو يتداخل مع قسم آخر',
                );
                return;
            }

            const nextSections = layout.sections.map((item, idx) =>
                idx === selection.index ? candidate : item,
            );
            commitChange({ ...layout, sections: nextSections });
        },
        [commitChange, layout, selection],
    );

    const deleteSelection = useCallback(() => {
        if (!selection) {
            return;
        }

        if (selection.kind === 'table') {
            const nextTables = layout.tables.filter(
                (_, idx) => idx !== selection.index,
            );
            const nextFlags = savedTableFlags.filter(
                (_, idx) => idx !== selection.index,
            );
            commitChange({ ...layout, tables: nextTables }, nextFlags);
            setSelection(null);
            return;
        }

        const nextSections = layout.sections.filter(
            (_, idx) => idx !== selection.index,
        );
        commitChange({ ...layout, sections: nextSections });
        setSelection(null);
    }, [commitChange, layout, savedTableFlags, selection]);

    useDesignerShortcuts({
        layout,
        selection,
        onUndo: handleUndo,
        onRedo: handleRedo,
        onEscape: () => {
            cancelDrag();
            setSelection(null);
        },
        onDeleteSelection: deleteSelection,
        onCommitLayout: commitChange,
    });

    const handleGridColumnsChange = useCallback(
        (newColumns: number | null) => {
            if (newColumns === null || newColumns < 1 || newColumns > 100) {
                return;
            }
            const stranded = checkGridResize(layout, newColumns, layout.rows);
            if (stranded.length > 0) {
                message.error(`لا يمكن تصغير الأعمدة: ${stranded.join('، ')}`);
                return;
            }
            commitChange({ ...layout, columns: newColumns });
        },
        [commitChange, layout],
    );

    const handleGridRowsChange = useCallback(
        (newRows: number | null) => {
            if (newRows === null || newRows < 1 || newRows > 100) {
                return;
            }
            const stranded = checkGridResize(layout, layout.columns, newRows);
            if (stranded.length > 0) {
                message.error(`لا يمكن تصغير الصفوف: ${stranded.join('، ')}`);
                return;
            }
            commitChange({ ...layout, rows: newRows });
        },
        [commitChange, layout],
    );

    const handleSave = useCallback(() => {
        if (problems.length > 0) {
            message.error('يرجى حل المشكلات الموضحة قبل الحفظ');
            return;
        }

        const payload = assignTableZones(layout);
        setServerErrors([]);
        setIsSaving(true);
        isSavingRef.current = true;

        router.put(route('floor.save', code), payload as any, {
            preserveScroll: true,
            onSuccess: () => {
                markSaved(payload);
                message.success('تم حفظ المخطط بنجاح');
            },
            onError: (errors) => {
                setServerErrors(Object.values(errors));
                message.error('تعذر حفظ المخطط، يرجى مراجعة الأخطاء');
            },
            onFinish: () => {
                setIsSaving(false);
                isSavingRef.current = false;
            },
        });
    }, [code, isSavingRef, layout, markSaved, problems.length]);

    const handleBackClick = useCallback(() => {
        if (!confirmLeaveIfDirty()) {
            return;
        }
        window.location.href = backUrl || '/admin/order-type-rows';
    }, [backUrl, confirmLeaveIfDirty]);

    const displayTitle = label || code;

    return (
        <CashierLayout>
            <Head title={`تصميم الشبكة — ${displayTitle}`} />
            <div
                dir="rtl"
                className="h-screen flex flex-col overflow-hidden bg-slate-100 select-none"
            >
                <DesignerToolbar
                    displayTitle={displayTitle}
                    tool={tool}
                    canUndo={canUndo}
                    canRedo={canRedo}
                    zoom={zoom}
                    columns={layout.columns}
                    rows={layout.rows}
                    enabled={layout.enabled}
                    isDirty={isDirty}
                    isSaving={isSaving}
                    hasProblems={problems.length > 0}
                    onBackClick={handleBackClick}
                    onToolChange={(nextTool) => {
                        setTool(nextTool);
                        cancelDrag();
                    }}
                    onUndo={handleUndo}
                    onRedo={handleRedo}
                    onZoomChange={setZoom}
                    onColumnsChange={handleGridColumnsChange}
                    onRowsChange={handleGridRowsChange}
                    onEnabledChange={(enabled) =>
                        commitChange({ ...layout, enabled })
                    }
                    onSave={handleSave}
                />

                <div className="flex-1 flex min-h-0 overflow-hidden">
                    <PropertiesPanel
                        layout={layout}
                        tool={tool}
                        selection={selection}
                        savedTableFlags={savedTableFlags}
                        numberPrefix={numberPrefix}
                        problems={problems}
                        serverErrorMessages={allServerErrorMessages}
                        onNumberPrefixChange={setNumberPrefix}
                        onUpdateTableLabel={updateTableLabel}
                        onUpdateTable={updateSelectedTable}
                        onUpdateSection={updateSelectedSection}
                        onDeleteSelection={deleteSelection}
                        onSelectTarget={(target) => {
                            setTool('select');
                            setSelection(target);
                        }}
                    />

                    <FloorCanvas
                        gridRef={gridRef}
                        layout={layout}
                        tool={tool}
                        zoom={zoom}
                        selection={selection}
                        ghostPreview={ghostPreview}
                        onSwitchTool={setTool}
                        onClearSelection={() => setSelection(null)}
                        onPointerMoveOnGrid={handlePointerMoveOnGrid}
                        onPointerLeaveGrid={clearHoverCell}
                        onCellPointerDown={handleCellPointerDown}
                        onSectionPointerDown={handleSectionPointerDown}
                        onSectionResizePointerDown={
                            handleSectionResizePointerDown
                        }
                        onTablePointerDown={handleTablePointerDown}
                        onTableResizePointerDown={handleTableResizePointerDown}
                    />
                </div>
            </div>
        </CashierLayout>
    );
}
