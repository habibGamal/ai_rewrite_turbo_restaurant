import React from 'react';
import { Button, Input, Switch } from 'antd';
import type { FloorLayout, TableLabelConfig } from '@/types';
import {
    formatTableLabel,
    resolveTableLabel,
    totalSeats,
} from '@/Components/Floor/floorLayout';

export interface EmptySelectionPanelProps {
    layout: FloorLayout;
    onUpdateTableLabel: (changes: Partial<TableLabelConfig>) => void;
    onSelectSection: (index: number) => void;
}

export function EmptySelectionPanel({
    layout,
    onUpdateTableLabel,
    onSelectSection,
}: EmptySelectionPanelProps) {
    const labelConfig = resolveTableLabel(layout.tableLabel);
    const previewLabel = formatTableLabel(
        { number: '7', capacity: 4 },
        labelConfig,
    );

    const stats = [
        { label: 'الطاولات', value: layout.tables.length },
        { label: 'المقاعد', value: totalSeats(layout.tables) },
        { label: 'الأقسام', value: layout.sections.length },
    ];

    return (
        <div className="space-y-4">
            <div className="grid grid-cols-3 gap-2 text-center">
                {stats.map((stat) => (
                    <div
                        key={stat.label}
                        className="bg-slate-50 p-2.5 rounded-lg border border-slate-200"
                    >
                        <div className="text-lg font-bold text-slate-800">
                            {stat.value}
                        </div>
                        <div className="text-[11px] text-slate-500">
                            {stat.label}
                        </div>
                    </div>
                ))}
            </div>

            <div className="bg-slate-50 p-3 rounded-lg border border-slate-200 space-y-3">
                <div className="text-xs font-semibold text-slate-700">
                    شكل اسم الطاولة
                </div>

                <div className="space-y-1">
                    <label className="block text-[11px] font-medium text-slate-600">
                        البادئة (تترك فارغة لعرض الرقم فقط)
                    </label>
                    <Input
                        size="small"
                        maxLength={20}
                        placeholder="مثال: طاولة أو T-"
                        value={labelConfig.prefix}
                        onChange={(e) =>
                            onUpdateTableLabel({ prefix: e.target.value })
                        }
                    />
                </div>

                <div className="space-y-2 pt-0.5">
                    <div className="flex items-center justify-between">
                        <span className="text-xs text-slate-600">
                            إظهار السعة (· 4 أفراد)
                        </span>
                        <Switch
                            size="small"
                            checked={labelConfig.showCapacity}
                            onChange={(showCapacity) =>
                                onUpdateTableLabel({ showCapacity })
                            }
                        />
                    </div>
                    <div className="flex items-center justify-between">
                        <span className="text-xs text-slate-600">
                            إظهار رقم الطلب عند الإشغال
                        </span>
                        <Switch
                            size="small"
                            checked={labelConfig.showOrderNumber}
                            onChange={(showOrderNumber) =>
                                onUpdateTableLabel({ showOrderNumber })
                            }
                        />
                    </div>
                    <div className="flex items-center justify-between">
                        <span className="text-xs text-slate-600">
                            إظهار المؤقت عند الإشغال
                        </span>
                        <Switch
                            size="small"
                            checked={labelConfig.showTimer}
                            onChange={(showTimer) =>
                                onUpdateTableLabel({ showTimer })
                            }
                        />
                    </div>
                </div>

                <div className="bg-white border border-slate-200 rounded-md px-3 py-2 text-center space-y-0.5">
                    <div className="text-[10px] text-slate-400">معاينة</div>
                    <div className="text-xs font-bold text-slate-800">
                        {previewLabel}
                    </div>
                    {(labelConfig.showOrderNumber || labelConfig.showTimer) && (
                        <div className="text-[11px] text-orange-600 font-medium">
                            {[
                                labelConfig.showOrderNumber ? 'طلب #12' : null,
                                labelConfig.showTimer ? '05:30' : null,
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        </div>
                    )}
                </div>
            </div>

            <div
                className={[
                    'bg-blue-50/70 p-3 rounded-lg border border-blue-100',
                    'space-y-2 text-xs text-slate-700 leading-relaxed',
                ].join(' ')}
            >
                <div className="font-semibold text-blue-900">
                    طريقة الاستخدام:
                </div>
                <ul className="list-disc pr-4 space-y-1">
                    <li>
                        <strong>إضافة طاولة:</strong> اختر الأداة ثم اضغط على أي
                        خلية فارغة لإضافة طاولة بالرقم التالي تلقائياً.
                    </li>
                    <li>
                        <strong>رسم قسم:</strong> اختر الأداة ثم اضغط واسحب عبر
                        الخلايا لرسم مساحة القسم.
                    </li>
                    <li>
                        <strong>تحديد:</strong> اضغط على طاولة أو قسم لتعديله،
                        واسحبه للنقل، أو اسحب مقبض الزاوية لتغيير الحجم.
                    </li>
                    <li>
                        <strong>لوحة المفاتيح:</strong> الأسهم للتحريك، Delete
                        للحذف، Esc لإلغاء التحديد.
                    </li>
                </ul>
            </div>

            {layout.sections.length > 0 && (
                <div className="space-y-1.5">
                    <div className="text-xs font-semibold text-slate-600">
                        الأقسام المرسومة
                    </div>
                    <div className="flex flex-wrap gap-1.5">
                        {layout.sections.map((sec, index) => (
                            <Button
                                key={index}
                                size="small"
                                onClick={() => onSelectSection(index)}
                                style={{ backgroundColor: sec.color }}
                            >
                                {sec.name}
                            </Button>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}
