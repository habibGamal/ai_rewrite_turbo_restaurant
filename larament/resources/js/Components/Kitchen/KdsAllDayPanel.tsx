import { kdsTokens } from '@/Components/Kitchen/kdsTokens';
import { allDayCounts, formatQty } from '@/Components/Kitchen/ticketModel';
import type { KdsTicket } from '@/types/kitchen';
import { CloseOutlined } from '@ant-design/icons';

interface KdsAllDayPanelProps {
    tickets: KdsTicket[];
    onClose: () => void;
}

export default function KdsAllDayPanel({ tickets, onClose }: KdsAllDayPanelProps) {
    const rows = allDayCounts(tickets);
    const total = rows.reduce((sum, row) => sum + row.quantity, 0);

    return (
        <aside
            className="flex w-[280px] shrink-0 flex-col"
            style={{ background: kdsTokens.bar, borderInlineStart: `1px solid ${kdsTokens.line}` }}
        >
            <div className="flex items-center justify-between gap-2 px-3 py-2" style={{ borderBottom: `1px solid ${kdsTokens.line}` }}>
                <div>
                    <div className="text-lg font-black">إجمالي اليوم</div>
                    <div className="text-sm font-bold" style={{ color: kdsTokens.textDim }}>
                        أصناف قيد التحضير
                    </div>
                </div>
                <button
                    type="button"
                    onClick={onClose}
                    aria-label="إغلاق الإجمالي"
                    className="kds-press flex h-12 w-12 items-center justify-center rounded-lg border-0"
                    style={{ background: kdsTokens.cardAlt, color: kdsTokens.text }}
                >
                    <CloseOutlined />
                </button>
            </div>
            <div className="px-3 py-2 text-lg font-black" style={{ borderBottom: `1px solid ${kdsTokens.line}` }}>
                المجموع{' '}
                <span dir="ltr" className="tabular-nums">
                    {formatQty(total)}
                </span>
            </div>
            <div className="min-h-0 flex-1 overflow-y-auto">
                {rows.length === 0 ? (
                    <div className="p-4 text-center text-lg font-bold" style={{ color: kdsTokens.textDim }}>
                        لا توجد أصناف قيد التحضير
                    </div>
                ) : (
                    rows.map((row) => (
                        <div
                            key={row.name}
                            className="flex items-center justify-between gap-3 px-3 py-3"
                            style={{ borderBottom: `1px solid ${kdsTokens.line}` }}
                        >
                            <span className="min-w-0 flex-1 text-lg font-bold leading-snug">{row.name}</span>
                            <span dir="ltr" className="text-3xl font-black tabular-nums" style={{ color: kdsTokens.timerWarning }}>
                                {formatQty(row.quantity)}
                            </span>
                        </div>
                    ))
                )}
            </div>
        </aside>
    );
}
