import React from 'react';
import {
    Badge,
    Button,
    InputNumber,
    Segmented,
    Space,
    Switch,
    Tooltip,
    Typography,
} from 'antd';
import {
    ArrowRightOutlined,
    BorderOuterOutlined,
    DragOutlined,
    PlusSquareOutlined,
    RedoOutlined,
    SaveOutlined,
    UndoOutlined,
    ZoomInOutlined,
    ZoomOutOutlined,
} from '@ant-design/icons';
import type { ToolMode } from '@/Components/Floor/floorLayout';

export const ZOOM_MIN = 60;
export const ZOOM_MAX = 150;
export const ZOOM_STEP = 15;

export interface DesignerToolbarProps {
    displayTitle: string;
    tool: ToolMode;
    canUndo: boolean;
    canRedo: boolean;
    zoom: number;
    columns: number;
    rows: number;
    enabled: boolean;
    isDirty: boolean;
    isSaving: boolean;
    hasProblems: boolean;
    onBackClick: () => void;
    onToolChange: (tool: ToolMode) => void;
    onUndo: () => void;
    onRedo: () => void;
    onZoomChange: (zoom: number) => void;
    onColumnsChange: (columns: number | null) => void;
    onRowsChange: (rows: number | null) => void;
    onEnabledChange: (enabled: boolean) => void;
    onSave: () => void;
}

export function DesignerToolbar({
    displayTitle,
    tool,
    canUndo,
    canRedo,
    zoom,
    columns,
    rows,
    enabled,
    isDirty,
    isSaving,
    hasProblems,
    onBackClick,
    onToolChange,
    onUndo,
    onRedo,
    onZoomChange,
    onColumnsChange,
    onRowsChange,
    onEnabledChange,
    onSave,
}: DesignerToolbarProps) {
    return (
        <header className="shrink-0 bg-white border-b border-slate-200 px-4 py-2.5 shadow-xs">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    <Button icon={<ArrowRightOutlined />} onClick={onBackClick}>
                        أنواع الطلبات
                    </Button>
                    <div>
                        <Typography.Title level={5} style={{ margin: 0 }}>
                            تصميم الشبكة — {displayTitle}
                        </Typography.Title>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <Segmented
                        value={tool}
                        onChange={(val) => onToolChange(val as ToolMode)}
                        options={[
                            {
                                label: 'تحديد',
                                value: 'select',
                                icon: <DragOutlined />,
                            },
                            {
                                label: 'إضافة طاولة',
                                value: 'add_table',
                                icon: <PlusSquareOutlined />,
                            },
                            {
                                label: 'رسم قسم',
                                value: 'draw_section',
                                icon: <BorderOuterOutlined />,
                            },
                        ]}
                    />

                    <Space.Compact>
                        <Tooltip title="تراجع (Ctrl+Z)">
                            <Button
                                icon={<UndoOutlined />}
                                disabled={!canUndo}
                                onClick={onUndo}
                                aria-label="تراجع"
                            />
                        </Tooltip>
                        <Tooltip title="إعادة (Ctrl+Y)">
                            <Button
                                icon={<RedoOutlined />}
                                disabled={!canRedo}
                                onClick={onRedo}
                                aria-label="إعادة"
                            />
                        </Tooltip>
                    </Space.Compact>

                    <Space.Compact className="items-center">
                        <Tooltip title="تصغير العرض">
                            <Button
                                icon={<ZoomOutOutlined />}
                                disabled={zoom <= ZOOM_MIN}
                                onClick={() =>
                                    onZoomChange(Math.max(ZOOM_MIN, zoom - ZOOM_STEP))
                                }
                                aria-label="تصغير العرض"
                            />
                        </Tooltip>
                        <Button onClick={() => onZoomChange(100)}>{zoom}%</Button>
                        <Tooltip title="تكبير العرض">
                            <Button
                                icon={<ZoomInOutlined />}
                                disabled={zoom >= ZOOM_MAX}
                                onClick={() =>
                                    onZoomChange(Math.min(ZOOM_MAX, zoom + ZOOM_STEP))
                                }
                                aria-label="تكبير العرض"
                            />
                        </Tooltip>
                    </Space.Compact>

                    <div className="flex items-center gap-1.5 bg-slate-50 px-2.5 py-1 rounded-md border border-slate-200">
                        <span className="text-xs text-slate-600">الأعمدة</span>
                        <InputNumber
                            size="small"
                            min={1}
                            max={100}
                            value={columns}
                            onChange={onColumnsChange}
                            style={{ width: 64 }}
                        />
                        <span className="text-xs text-slate-600 mr-1">الصفوف</span>
                        <InputNumber
                            size="small"
                            min={1}
                            max={100}
                            value={rows}
                            onChange={onRowsChange}
                            style={{ width: 64 }}
                        />
                    </div>
                </div>

                <div className="flex items-center gap-4">
                    <div className="flex flex-col">
                        <div className="flex items-center gap-2">
                            <Switch checked={enabled} onChange={onEnabledChange} />
                            <span className="text-sm font-medium">
                                إظهار المخطط في الكاشير
                            </span>
                        </div>
                        <span className="text-[11px] text-slate-500">
                            يظهر في الكاشير عند التفعيل ووجود طاولة على الأقل
                        </span>
                    </div>

                    {isDirty && (
                        <Badge
                            status="warning"
                            text={
                                <span className="text-xs text-amber-700 font-medium">
                                    تغييرات غير محفوظة
                                </span>
                            }
                        />
                    )}

                    <Button
                        type="primary"
                        icon={<SaveOutlined />}
                        loading={isSaving}
                        disabled={hasProblems}
                        onClick={onSave}
                    >
                        حفظ
                    </Button>
                </div>
            </div>
        </header>
    );
}
