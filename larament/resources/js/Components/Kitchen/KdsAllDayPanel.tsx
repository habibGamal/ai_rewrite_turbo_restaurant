import { kdsTokens } from '@/Components/Kitchen/kdsTokens';
import { allDayCounts, formatQty } from '@/Components/Kitchen/ticketModel';
import type { KdsTicket } from '@/types/kitchen';
import { CloseOutlined } from '@ant-design/icons';
import { memo, useMemo } from 'react';

interface KdsAllDayPanelProps {
    tickets: KdsTicket[];
    onClose: () => void;
}

const KdsAllDayPanel = memo(function KdsAllDayPanel({ tickets, onClose }: KdsAllDayPanelProps) {
    const rows = useMemo(() => allDayCounts(tickets), [tickets]);
    const total = useMemo(() => rows.reduce((sum, row) => sum + row.quantity, 0), [rows]);

    return (
        <aside
            className="flex w-[300px] shrink-0 flex-col"
            style={{
                background: kdsTokens.bar,
                borderInlineEnd: `1px solid ${kdsTokens.lineStrong}`,
            }}
        >
            <div
                className="flex items-center justify-between gap-2 px-3.5 py-3"
                style={{ borderBottom: `1px solid ${kdsTokens.line}` }}
            >
                <div>
                    <div className="text-lg font-black">إجمالي الأصناف</div>
                    <div className="text-xs font-bold" style={{ color: kdsTokens.textDim }}>
                        أصناف قيد التحضير الآن
                    </div>
                </div>
                <button
                    type="button"
                    onClick={onClose}
                    aria-label="إغلاق الإجمالي"
                    className="kds-press flex h-11 w-11 items-center justify-center rounded-xl border-0"
                    style={{ background: kdsTokens.cardAlt, color: kdsTokens.text }}
                >
                    <CloseOutlined />
                </button>
            </div>

            <div
                className="flex items-center justify-between px-3.5 py-2.5 text-base font-black"
                style={{ background: 'rgba(255,255,255,0.02)', borderBottom: `1px solid ${kdsTokens.line}` }}
            >
                <span style={{ color: kdsTokens.textSoft }}>المجموع الكلي</span>
                <span
                    dir="ltr"
                    className="rounded-lg px-2.5 py-0.5 text-xl font-black tabular-nums"
                    style={{ background: kdsTokens.qtyBg, color: kdsTokens.text }}
                >
                    {formatQty(total)}
                </span>
            </div>

            <div className="kds-scroll min-h-0 flex-1 overflow-y-auto p-2">
                {rows.length === 0 ? (
                    <div className="p-6 text-center text-base font-bold" style={{ color: kdsTokens.textDim }}>
                        لا توجد أصناف قيد التحضير
                    </div>
                ) : (
                    <div className="flex flex-col gap-1.5">
                        {rows.map((row) => (
                            <div
                                key={row.name}
                                className="flex items-center justify-between gap-3 rounded-xl px-3 py-2.5"
                                style={{
                                    background: kdsTokens.card,
                                    boxShadow: `inset 0 0 0 1px ${kdsTokens.line}`,
                                }}
                            >
                                <span className="min-w-0 flex-1 text-base font-bold leading-snug">{row.name}</span>
                                <span
                                    dir="ltr"
                                    className="inline-flex min-w-[40px] items-center justify-center rounded-lg px-2 py-1 text-xl font-black tabular-nums"
                                    style={{ background: 'rgba(251, 191, 36, 0.14)', color: kdsTokens.timerWarning }}
                                >
                                    {formatQty(row.quantity)}
                                </span>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </aside>
    );
});

export default KdsAllDayPanel;
