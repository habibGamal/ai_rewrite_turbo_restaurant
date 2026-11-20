import React from 'react';
import {
    Button,
    Input,
    InputNumber,
    Popconfirm,
    Segmented,
    Switch,
    Tag,
    Tooltip,
    Typography,
} from 'antd';
import { DeleteOutlined } from '@ant-design/icons';
import type {
    FloorLayout,
    FloorSection,
    FloorTable,
    TableLabelConfig,
    TableShape,
} from '@/types';
import {
    findZoneForCell,
    nextTableNumber,
    SECTION_COLOR_PALETTE,
    type LayoutProblem,
    type SelectionTarget,
    type ToolMode,
} from '@/Components/Floor/floorLayout';
import { EmptySelectionPanel } from '@/Components/Floor/EmptySelectionPanel';
import { ProblemsList } from '@/Components/Floor/ProblemsList';

const SAVED_TABLE_NUMBER_TOOLTIP =
    'لا يمكن تغيير رقم طاولة محفوظة حتى لا تنفصل عنها الطلبات المفتوحة';

interface TablePropertiesProps {
    table: FloorTable;
    isSaved: boolean;
    columns: number;
    rows: number;
    currentSectionName: string;
    onUpdate: (changes: Partial<FloorTable>) => void;
    onDelete: () => void;
}

function TableProperties({
    table,
    isSaved,
    columns,
    rows,
    currentSectionName,
    onUpdate,
    onDelete,
}: TablePropertiesProps) {
    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between border-b border-slate-100 pb-2">
                <Typography.Text strong>
                    خصائص الطاولة ({table.number})
                </Typography.Text>
                <Tag color={table.active ? 'green' : 'default'}>
                    {table.active ? 'نشطة' : 'غير نشطة'}
                </Tag>
            </div>

            <div className="space-y-1">
                <label className="block text-xs font-medium text-slate-600">
                    رقم الطاولة
                </label>
                {isSaved ? (
                    <Tooltip title={SAVED_TABLE_NUMBER_TOOLTIP}>
                        <div>
                            <Input value={table.number} disabled />
                        </div>
                    </Tooltip>
                ) : (
                    <Input
                        value={table.number}
                        maxLength={50}
                        onChange={(e) => onUpdate({ number: e.target.value })}
                    />
                )}
            </div>

            <div className="space-y-1">
                <label className="block text-xs font-medium text-slate-600">
                    السعة (عدد الأفراد)
                </label>
                <InputNumber
                    min={1}
                    max={100}
                    className="w-full"
                    value={table.capacity}
                    onChange={(val) =>
                        val !== null && onUpdate({ capacity: val })
                    }
                />
            </div>

            <div className="space-y-1">
                <label className="block text-xs font-medium text-slate-600">
                    شكل الطاولة
                </label>
                <Segmented
                    block
                    value={table.shape ?? 'square'}
                    onChange={(val) => onUpdate({ shape: val as TableShape })}
                    options={[
                        { label: 'مربعة', value: 'square' },
                        { label: 'دائرية', value: 'round' },
                    ]}
                />
            </div>

            <div className="grid grid-cols-2 gap-2">
                <div className="space-y-1">
                    <label className="block text-xs font-medium text-slate-600">
                        العرض (خلايا)
                    </label>
                    <InputNumber
                        min={1}
                        max={columns}
                        className="w-full"
                        value={table.colSpan}
                        onChange={(val) =>
                            val !== null && onUpdate({ colSpan: val })
                        }
                    />
                </div>
                <div className="space-y-1">
                    <label className="block text-xs font-medium text-slate-600">
                        الارتفاع (خلايا)
                    </label>
                    <InputNumber
                        min={1}
                        max={rows}
                        className="w-full"
                        value={table.rowSpan}
                        onChange={(val) =>
                            val !== null && onUpdate({ rowSpan: val })
                        }
                    />
                </div>
            </div>

            <div className="flex items-center justify-between py-1">
                <span className="text-xs font-medium text-slate-600">
                    الطاولة نشطة
                </span>
                <Switch
                    checked={table.active}
                    onChange={(active) => onUpdate({ active })}
                />
            </div>

            <div
                className={[
                    'bg-slate-50 px-3 py-2 rounded-md border border-slate-200',
                    'text-xs text-slate-600 flex items-center justify-between',
                ].join(' ')}
            >
                <span>القسم الحالي:</span>
                <strong className="text-slate-800">
                    {currentSectionName || 'بدون قسم'}
                </strong>
            </div>

            <Popconfirm
                title="حذف الطاولة"
                description={`هل أنت متأكد من حذف الطاولة ${table.number}؟`}
                okText="حذف"
                cancelText="إلغاء"
                okButtonProps={{ danger: true }}
                onConfirm={onDelete}
            >
                <Button danger block icon={<DeleteOutlined />}>
                    حذف الطاولة
                </Button>
            </Popconfirm>
        </div>
    );
}

interface SectionPropertiesProps {
    section: FloorSection;
    onUpdate: (changes: Partial<FloorSection>) => void;
    onDelete: () => void;
}

function SectionProperties({
    section,
    onUpdate,
    onDelete,
}: SectionPropertiesProps) {
    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between border-b border-slate-100 pb-2">
                <Typography.Text strong>
                    خصائص القسم ({section.name})
                </Typography.Text>
                <Tag>
                    {section.colSpan}×{section.rowSpan}
                </Tag>
            </div>

            <div className="space-y-1">
                <label className="block text-xs font-medium text-slate-600">
                    اسم القسم
                </label>
                <Input
                    value={section.name}
                    onChange={(e) => onUpdate({ name: e.target.value })}
                />
            </div>

            <div className="space-y-2">
                <label className="block text-xs font-medium text-slate-600">
                    لون القسم
                </label>
                <div className="flex flex-wrap gap-2">
                    {SECTION_COLOR_PALETTE.map((swatch) => (
                        <button
                            key={swatch}
                            type="button"
                            onClick={() => onUpdate({ color: swatch })}
                            className="w-7 h-7 rounded-full border transition-transform"
                            style={{
                                backgroundColor: swatch,
                                borderColor:
                                    section.color === swatch
                                        ? '#1677ff'
                                        : '#cbd5e1',
                                transform:
                                    section.color === swatch
                                        ? 'scale(1.12)'
                                        : 'scale(1)',
                            }}
                            aria-label={`لون ${swatch}`}
                        />
                    ))}
                </div>
                <Input
                    type="color"
                    value={section.color}
                    onChange={(e) => onUpdate({ color: e.target.value })}
                />
            </div>

            <Popconfirm
                title="حذف القسم"
                description={`هل أنت متأكد من حذف القسم "${section.name}"؟`}
                okText="حذف"
                cancelText="إلغاء"
                okButtonProps={{ danger: true }}
                onConfirm={onDelete}
            >
                <Button danger block icon={<DeleteOutlined />}>
                    حذف القسم
                </Button>
            </Popconfirm>
        </div>
    );
}

export interface PropertiesPanelProps {
    layout: FloorLayout;
    tool: ToolMode;
    selection: SelectionTarget | null;
    savedTableFlags: boolean[];
    numberPrefix: string;
    problems: LayoutProblem[];
    serverErrorMessages: string[];
    onNumberPrefixChange: (prefix: string) => void;
    onUpdateTableLabel: (changes: Partial<TableLabelConfig>) => void;
    onUpdateTable: (changes: Partial<FloorTable>) => void;
    onUpdateSection: (changes: Partial<FloorSection>) => void;
    onDeleteSelection: () => void;
    onSelectTarget: (target: SelectionTarget) => void;
}

export function PropertiesPanel({
    layout,
    tool,
    selection,
    savedTableFlags,
    numberPrefix,
    problems,
    serverErrorMessages,
    onNumberPrefixChange,
    onUpdateTableLabel,
    onUpdateTable,
    onUpdateSection,
    onDeleteSelection,
    onSelectTarget,
}: PropertiesPanelProps) {
    const selectedTable =
        selection?.kind === 'table'
            ? (layout.tables[selection.index] ?? null)
            : null;
    const isSelectedTableSaved =
        selection?.kind === 'table'
            ? Boolean(savedTableFlags[selection.index])
            : false;
    const selectedSection =
        selection?.kind === 'section'
            ? (layout.sections[selection.index] ?? null)
            : null;

    return (
        <aside
            className={[
                'w-80 shrink-0 bg-white border-l border-slate-200',
                'overflow-y-auto p-4 flex flex-col gap-4',
            ].join(' ')}
        >
            {(tool === 'add_table' || selection === null) && (
                <div className="bg-slate-50 p-3 rounded-lg border border-slate-200 space-y-2">
                    <div className="text-xs font-semibold text-slate-700">
                        بادئة الترقيم التلقائي (اختياري)
                    </div>
                    <Input
                        size="small"
                        placeholder="مثال: VIP-"
                        value={numberPrefix}
                        onChange={(e) => onNumberPrefixChange(e.target.value)}
                        allowClear
                    />
                    <div className="text-[11px] text-slate-500">
                        الرقم التالي المقترح:{' '}
                        <strong className="text-slate-800">
                            {nextTableNumber(layout.tables, numberPrefix)}
                        </strong>
                    </div>
                </div>
            )}

            {selectedTable && selection?.kind === 'table' && (
                <TableProperties
                    table={selectedTable}
                    isSaved={isSelectedTableSaved}
                    columns={layout.columns}
                    rows={layout.rows}
                    currentSectionName={findZoneForCell(
                        layout.sections,
                        selectedTable,
                    )}
                    onUpdate={onUpdateTable}
                    onDelete={onDeleteSelection}
                />
            )}

            {selectedSection && selection?.kind === 'section' && (
                <SectionProperties
                    section={selectedSection}
                    onUpdate={onUpdateSection}
                    onDelete={onDeleteSelection}
                />
            )}

            {selection === null && (
                <EmptySelectionPanel
                    layout={layout}
                    onUpdateTableLabel={onUpdateTableLabel}
                    onSelectSection={(index) =>
                        onSelectTarget({ kind: 'section', index })
                    }
                />
            )}
            <ProblemsList
                problems={problems}
                serverErrorMessages={serverErrorMessages}
                onSelectProblemTarget={onSelectTarget}
            />
        </aside>
    );
}
