import React from 'react';
import { Button } from 'antd';
import { AppstoreAddOutlined } from '@ant-design/icons';
import type { FloorLayout } from '@/types';
import {
    gridPositionStyle,
    type Cell,
    type GhostPreview,
    type SelectionTarget,
    type ToolMode,
} from '@/Components/Floor/floorLayout';

const BASE_CELL_SIZE = 84;

export interface FloorCanvasProps {
    gridRef: React.RefObject<HTMLDivElement>;
    layout: FloorLayout;
    tool: ToolMode;
    zoom: number;
    selection: SelectionTarget | null;
    ghostPreview: GhostPreview | null;
    onSwitchTool: (tool: ToolMode) => void;
    onClearSelection: () => void;
    onPointerMoveOnGrid: (event: React.PointerEvent<HTMLDivElement>) => void;
    onPointerLeaveGrid: () => void;
    onCellPointerDown: (cell: Cell, event: React.PointerEvent<HTMLDivElement>) => void;
    onSectionPointerDown: (index: number, event: React.PointerEvent<HTMLDivElement>) => void;
    onSectionResizePointerDown: (index: number, event: React.PointerEvent<HTMLDivElement>) => void;
    onTablePointerDown: (index: number, event: React.PointerEvent<HTMLDivElement>) => void;
    onTableResizePointerDown: (index: number, event: React.PointerEvent<HTMLDivElement>) => void;
}

export function FloorCanvas({
    gridRef,
    layout,
    tool,
    zoom,
    selection,
    ghostPreview,
    onSwitchTool,
    onClearSelection,
    onPointerMoveOnGrid,
    onPointerLeaveGrid,
    onCellPointerDown,
    onSectionPointerDown,
    onSectionResizePointerDown,
    onTablePointerDown,
    onTableResizePointerDown,
}: FloorCanvasProps) {
    const scaledCellSize = Math.round(BASE_CELL_SIZE * (zoom / 100));

    return (
        <main
            className="flex-1 min-w-0 overflow-auto p-6 flex flex-col items-center justify-start gap-4"
            onClick={(e) => {
                if (e.target === e.currentTarget) {
                    onClearSelection();
                }
            }}
        >
            {layout.tables.length === 0 && (
                <div
                    className={[
                        'bg-white border border-dashed border-slate-300 rounded-xl',
                        'px-5 py-3 shadow-2xs flex items-center gap-3 text-sm text-slate-600',
                    ].join(' ')}
                >
                    <AppstoreAddOutlined className="text-lg text-purple-600" />
                    <span>
                        لا توجد طاولات في المخطط بعد — اختر أداة{' '}
                        <strong>«إضافة طاولة»</strong> ثم اضغط على أي خلية في
                        الشبكة لإضافة أول طاولة.
                    </span>
                    {tool !== 'add_table' && (
                        <Button
                            size="small"
                            type="primary"
                            onClick={() => onSwitchTool('add_table')}
                        >
                            تفعيل إضافة طاولة
                        </Button>
                    )}
                </div>
            )}

            <div
                ref={gridRef}
                dir="rtl"
                onPointerMove={onPointerMoveOnGrid}
                onPointerLeave={onPointerLeaveGrid}
                style={{
                    display: 'grid',
                    gridTemplateColumns: `repeat(${layout.columns}, ${scaledCellSize}px)`,
                    gridTemplateRows: `repeat(${layout.rows}, ${scaledCellSize}px)`,
                    gap: 6,
                    padding: 12,
                    background: '#ffffff',
                    borderRadius: 16,
                    border: '1px solid #e2e8f0',
                    boxShadow: '0 1px 3px rgba(0, 0, 0, 0.05)',
                    position: 'relative',
                    touchAction: 'none',
                }}
            >
                {Array.from(
                    { length: layout.rows * layout.columns },
                    (_, cellIndex) => {
                        const col = cellIndex % layout.columns;
                        const row = Math.floor(cellIndex / layout.columns);
                        return (
                            <div
                                key={`cell-${col}-${row}`}
                                data-col={col}
                                data-row={row}
                                onPointerDown={(e) => onCellPointerDown({ col, row }, e)}
                                style={{
                                    gridColumn: col + 1,
                                    gridRow: row + 1,
                                    zIndex: 1,
                                    borderRadius: 8,
                                    border: '1px dashed #cbd5e1',
                                    backgroundColor: 'rgba(248, 250, 252, 0.55)',
                                    cursor:
                                        tool === 'add_table' || tool === 'draw_section'
                                            ? 'crosshair'
                                            : 'default',
                                }}
                            />
                        );
                    },
                )}

                {layout.sections.map((section, index) => {
                    const isSelected =
                        selection?.kind === 'section' && selection.index === index;

                    return (
                        <div
                            key={`section-${index}`}
                            onPointerDown={(e) => onSectionPointerDown(index, e)}
                            style={{
                                ...gridPositionStyle(section),
                                zIndex: 2,
                                backgroundColor: section.color,
                                borderRadius: 12,
                                border: isSelected
                                    ? '2px solid #2563eb'
                                    : '1px solid rgba(15, 23, 42, 0.14)',
                                padding: 6,
                                position: 'relative',
                                cursor: tool === 'select' ? 'grab' : 'crosshair',
                            }}
                        >
                            <span
                                className={[
                                    'inline-block bg-white/85 backdrop-blur-xs text-slate-800',
                                    'text-xs font-semibold px-2 py-0.5 rounded shadow-2xs',
                                ].join(' ')}
                            >
                                {section.name}
                            </span>

                            {tool === 'select' && (
                                <div
                                    title="تغيير حجم القسم"
                                    onPointerDown={(e) =>
                                        onSectionResizePointerDown(index, e)
                                    }
                                    style={{
                                        position: 'absolute',
                                        bottom: 4,
                                        left: 4,
                                        width: 14,
                                        height: 14,
                                        borderRadius: 3,
                                        backgroundColor: isSelected
                                            ? '#2563eb'
                                            : 'rgba(37, 99, 235, 0.65)',
                                        border: '2px solid #ffffff',
                                        cursor: 'nesw-resize',
                                        zIndex: 5,
                                    }}
                                />
                            )}
                        </div>
                    );
                })}

                {layout.tables.map((table, index) => {
                    const isSelected =
                        selection?.kind === 'table' && selection.index === index;
                    const isRound = table.shape === 'round';

                    return (
                        <div
                            key={`table-${index}-${table.number}`}
                            role="button"
                            tabIndex={0}
                            onPointerDown={(e) => onTablePointerDown(index, e)}
                            style={{
                                ...gridPositionStyle(table),
                                zIndex: 3,
                                borderRadius: isRound ? '9999px' : 12,
                                backgroundColor: !table.active
                                    ? '#f1f5f9'
                                    : isSelected
                                      ? '#ede9fe'
                                      : '#ffffff',
                                border: isSelected
                                    ? '2px solid #7e57c2'
                                    : !table.active
                                      ? '1px dashed #94a3b8'
                                      : '1px solid #64748b',
                                opacity: table.active ? 1 : 0.6,
                                boxShadow: isSelected
                                    ? '0 0 0 3px rgba(126, 87, 194, 0.2)'
                                    : '0 1px 2px rgba(0, 0, 0, 0.06)',
                                display: 'flex',
                                flexDirection: 'column',
                                alignItems: 'center',
                                justifyContent: 'center',
                                position: 'relative',
                                cursor: tool === 'select' ? 'grab' : 'pointer',
                                padding: 4,
                            }}
                        >
                            <div className="font-bold text-sm text-slate-800 leading-tight text-center">
                                {table.number} · {table.capacity} أفراد
                            </div>
                            {!table.active && (
                                <div className="text-[10px] text-slate-500 mt-0.5">
                                    غير نشطة
                                </div>
                            )}

                            {tool === 'select' && (
                                <div
                                    title="تغيير حجم الطاولة"
                                    onPointerDown={(e) =>
                                        onTableResizePointerDown(index, e)
                                    }
                                    style={{
                                        position: 'absolute',
                                        bottom: isRound ? 6 : 4,
                                        left: isRound ? 6 : 4,
                                        width: 13,
                                        height: 13,
                                        borderRadius: 3,
                                        backgroundColor: isSelected
                                            ? '#7e57c2'
                                            : '#64748b',
                                        border: '2px solid #ffffff',
                                        cursor: 'nesw-resize',
                                    }}
                                />
                            )}
                        </div>
                    );
                })}

                {ghostPreview && (
                    <div
                        style={{
                            ...gridPositionStyle(ghostPreview),
                            zIndex: 4,
                            pointerEvents: 'none',
                            borderRadius:
                                ghostPreview.kind === 'table' &&
                                ghostPreview.shape === 'round'
                                    ? '9999px'
                                    : 12,
                            backgroundColor: ghostPreview.valid
                                ? 'rgba(34, 197, 94, 0.22)'
                                : 'rgba(239, 68, 68, 0.24)',
                            border: ghostPreview.valid
                                ? '2px dashed #16a34a'
                                : '2px dashed #dc2626',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            color: ghostPreview.valid ? '#15803d' : '#b91c1c',
                            fontWeight: 700,
                            fontSize: 12,
                        }}
                    >
                        {ghostPreview.label}
                    </div>
                )}
            </div>
        </main>
    );
}
