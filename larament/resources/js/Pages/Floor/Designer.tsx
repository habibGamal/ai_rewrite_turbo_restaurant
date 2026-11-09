import React, { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { Alert, Button, Form, Input, InputNumber, Modal, Space, Switch } from 'antd';
import CashierLayout from '@/Layouts/CashierLayout';
import type { FloorLayout, FloorRect, FloorSection, FloorTable } from '@/types';

interface DesignerProps {
    code: string;
    layout: FloorLayout | null;
}

interface Cell {
    col: number;
    row: number;
}

const emptyLayout: FloorLayout = {
    version: 1,
    enabled: false,
    columns: 8,
    rows: 6,
    tables: [],
    sections: [],
};

function gridPosition(rect: FloorRect): React.CSSProperties {
    return {
        gridColumn: `${rect.col + 1} / span ${rect.colSpan}`,
        gridRow: `${rect.row + 1} / span ${rect.rowSpan}`,
    };
}

interface FloorGridProps {
    layout: FloorLayout;
    selected: number | null;
    onPlace: (cell: Cell) => void;
    onSelect: (index: number) => void;
}

function FloorGrid({ layout, selected, onPlace, onSelect }: FloorGridProps) {
    return (
        <div
            style={{
                display: 'grid',
                gridTemplateColumns: `repeat(${layout.columns}, minmax(65px, 1fr))`,
                gridTemplateRows: `repeat(${layout.rows}, 70px)`,
                gap: 3,
                overflowX: 'auto',
            }}
        >
            {layout.sections.map((section, index) => (
                <div
                    key={`section-${index}`}
                    style={{ ...gridPosition(section), background: section.color }}
                >
                    {section.name}
                </div>
            ))}
            {Array.from({ length: layout.rows * layout.columns }, (_, index) => (
                <button
                    key={index}
                    type="button"
                    onClick={() => onPlace({
                        col: index % layout.columns,
                        row: Math.floor(index / layout.columns),
                    })}
                    style={{
                        gridColumn: index % layout.columns + 1,
                        gridRow: Math.floor(index / layout.columns) + 1,
                        zIndex: 1,
                        border: '1px solid #bbb',
                        background: 'transparent',
                    }}
                />
            ))}
            {layout.tables.map((table, index) => (
                <button
                    key={table.number}
                    type="button"
                    onClick={() => onSelect(index)}
                    style={{
                        ...gridPosition(table),
                        zIndex: 2,
                        background: index === selected ? '#bae0ff' : 'white',
                        border: '1px solid #1677ff',
                    }}
                >
                    {table.number}
                </button>
            ))}
        </div>
    );
}

interface TableEditorProps {
    table: FloorTable;
    onChange: (changes: Partial<FloorTable>) => void;
    onDelete: () => void;
}

function TableEditor({ table, onChange, onDelete }: TableEditorProps) {
    return (
        <Space wrap>
            طاولة {table.number}
            السعة
            <InputNumber
                min={1}
                value={table.capacity}
                onChange={(value) => value && onChange({ capacity: value })}
            />
            المنطقة
            <Input
                value={table.zone}
                onChange={(event) => onChange({ zone: event.target.value })}
            />
            العرض
            <InputNumber
                min={1}
                value={table.colSpan}
                onChange={(value) => value && onChange({ colSpan: value })}
            />
            الارتفاع
            <InputNumber
                min={1}
                value={table.rowSpan}
                onChange={(value) => value && onChange({ rowSpan: value })}
            />
            نشطة
            <Switch
                checked={table.active}
                onChange={(active) => onChange({ active })}
            />
            <Button danger onClick={onDelete}>
                حذف
            </Button>
        </Space>
    );
}

interface SectionEditorProps {
    section: FloorSection;
    onChange: (changes: Partial<FloorSection>) => void;
    onDelete: () => void;
}

function SectionEditor({ section, onChange, onDelete }: SectionEditorProps) {
    return (
        <Space wrap>
            الاسم
            <Input
                value={section.name}
                onChange={(event) => onChange({ name: event.target.value })}
            />
            المنطقة
            <Input
                value={section.zone}
                onChange={(event) => onChange({ zone: event.target.value })}
            />
            اللون
            <Input
                type="color"
                value={section.color}
                onChange={(event) => onChange({ color: event.target.value })}
            />
            العمود
            <InputNumber
                min={0}
                value={section.col}
                onChange={(value) => value !== null && onChange({ col: value })}
            />
            الصف
            <InputNumber
                min={0}
                value={section.row}
                onChange={(value) => value !== null && onChange({ row: value })}
            />
            العرض
            <InputNumber
                min={1}
                value={section.colSpan}
                onChange={(value) => value && onChange({ colSpan: value })}
            />
            الارتفاع
            <InputNumber
                min={1}
                value={section.rowSpan}
                onChange={(value) => value && onChange({ rowSpan: value })}
            />
            <Button danger onClick={onDelete}>
                حذف القسم
            </Button>
        </Space>
    );
}

export default function Designer({ code, layout }: DesignerProps) {
    const { errors } = usePage().props;
    const [document, setDocument] = useState<FloorLayout>(layout ?? emptyLayout);
    const [selected, setSelected] = useState<number | null>(null);
    const [selectedSection, setSelectedSection] = useState<number | null>(null);
    const [newTableCell, setNewTableCell] = useState<Cell | null>(null);
    const [form] = Form.useForm<{ number: string }>();

    const patch = (changes: Partial<FloorLayout>) => {
        setDocument((current) => ({ ...current, ...changes }));
    };

    const updateTable = (changes: Partial<FloorTable>) => {
        patch({
            tables: document.tables.map((table, index) =>
                index === selected ? { ...table, ...changes } : table
            ),
        });
    };

    const updateSection = (changes: Partial<FloorSection>) => {
        patch({
            sections: document.sections.map((section, index) =>
                index === selectedSection ? { ...section, ...changes } : section
            ),
        });
    };

    const placeTable = (cell: Cell) => {
        if (selected !== null) {
            updateTable(cell);
            return;
        }

        form.resetFields();
        setNewTableCell(cell);
    };

    const addTable = ({ number }: { number: string }) => {
        if (!newTableCell) return;

        patch({
            tables: [...document.tables, {
                number,
                capacity: 4,
                zone: '',
                active: true,
                ...newTableCell,
                colSpan: 1,
                rowSpan: 1,
            }],
        });
        setSelected(document.tables.length);
        setNewTableCell(null);
    };

    const addSection = () => {
        patch({
            sections: [...document.sections, {
                name: 'قسم',
                zone: '',
                color: '#e8f5e9',
                col: 0,
                row: 0,
                colSpan: 1,
                rowSpan: 1,
            }],
        });
        setSelectedSection(document.sections.length);
    };

    const table = selected === null ? null : document.tables[selected];
    const section = selectedSection === null ? null : document.sections[selectedSection];

    return (
        <CashierLayout>
            <Head title="تصميم الشبكة" />
            <main dir="rtl" className="p-6 space-y-4">
                <a href="/admin/order-type-rows">العودة إلى أنواع الطلبات</a>
                <h1>تصميم الشبكة - {code}</h1>
                {Object.keys(errors).length > 0 && (
                    <Alert
                        type="error"
                        showIcon
                        message="تعذر حفظ المخطط"
                        description={Object.values(errors).join('، ')}
                    />
                )}
                <Space wrap>
                    تفعيل
                    <Switch
                        checked={document.enabled}
                        onChange={(enabled) => patch({ enabled })}
                    />
                    الأعمدة
                    <InputNumber
                        min={1}
                        max={100}
                        value={document.columns}
                        onChange={(value) => value && patch({ columns: value })}
                    />
                    الصفوف
                    <InputNumber
                        min={1}
                        max={100}
                        value={document.rows}
                        onChange={(value) => value && patch({ rows: value })}
                    />
                    <Button onClick={() => setSelected(null)}>إضافة طاولة</Button>
                    <Button onClick={addSection}>إضافة قسم</Button>
                    <Button
                        type="primary"
                        onClick={() => router.put(route('floor.save', code), document)}
                    >
                        حفظ
                    </Button>
                </Space>
                <FloorGrid
                    layout={document}
                    selected={selected}
                    onPlace={placeTable}
                    onSelect={setSelected}
                />
                {table && (
                    <TableEditor
                        table={table}
                        onChange={updateTable}
                        onDelete={() => {
                            patch({ tables: document.tables.filter((_, index) => index !== selected) });
                            setSelected(null);
                        }}
                    />
                )}
                <div>
                    الأقسام
                    {document.sections.map((item, index) => (
                        <Button key={index} onClick={() => setSelectedSection(index)}>
                            {item.name}
                        </Button>
                    ))}
                </div>
                {section && (
                    <SectionEditor
                        section={section}
                        onChange={updateSection}
                        onDelete={() => {
                            patch({ sections: document.sections.filter((_, index) => index !== selectedSection) });
                            setSelectedSection(null);
                        }}
                    />
                )}
                <Modal
                    title="إضافة طاولة"
                    open={newTableCell !== null}
                    onCancel={() => setNewTableCell(null)}
                    onOk={() => form.submit()}
                    okText="إضافة"
                    cancelText="إلغاء"
                >
                    <Form form={form} layout="vertical" onFinish={addTable}>
                        <Form.Item
                            name="number"
                            label="رقم الطاولة"
                            rules={[
                                { required: true, whitespace: true, message: 'رقم الطاولة مطلوب' },
                                { max: 50, message: 'رقم الطاولة لا يتجاوز 50 حرفاً' },
                                {
                                    validator: (_, value: string) => document.tables.some((item) => item.number === value)
                                        ? Promise.reject(new Error('رقم الطاولة مستخدم'))
                                        : Promise.resolve(),
                                },
                            ]}
                        >
                            <Input maxLength={50} />
                        </Form.Item>
                    </Form>
                </Modal>
            </main>
        </CashierLayout>
    );
}
