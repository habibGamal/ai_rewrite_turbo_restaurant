import { allDayCounts, formatQty } from '@/Components/Kitchen/ticketModel';
import type { KdsTicket } from '@/types/kitchen';
import { CloseOutlined } from '@ant-design/icons';
import { Button } from 'antd';

interface KdsAllDayPanelProps {
    tickets: KdsTicket[];
    onClose: () => void;
}

export default function KdsAllDayPanel({ tickets, onClose }: KdsAllDayPanelProps) {
    const rows = allDayCounts(tickets);
    const total = rows.reduce((sum, row) => sum + row.quantity, 0);

    return (
        <aside className="flex w-[300px] shrink-0 flex-col border-l border-white/10 bg-zinc-900">
            <div className="flex items-center justify-between gap-2 border-b border-white/10 px-3 py-2">
                <div>
                    <div className="text-xl font-black">إجمالي اليوم</div>
                    <div className="text-sm font-bold text-zinc-400">أصناف قيد التحضير</div>
                </div>
                <Button icon={<CloseOutlined />} onClick={onClose} style={{ height: 48 }} aria-label="إغلاق الإجمالي" />
            </div>
            <div className="border-b border-white/10 px-3 py-2 text-lg font-black">
                المجموع <span dir="ltr" className="tabular-nums">{formatQty(total)}</span>
            </div>
            <div className="min-h-0 flex-1 overflow-y-auto">
                {rows.length === 0 ? (
                    <div className="p-4 text-center text-lg font-bold text-zinc-400">لا توجد أصناف قيد التحضير</div>
                ) : (
                    rows.map((row) => (
                        <div
                            key={row.name}
                            className="flex items-center justify-between gap-3 border-b border-white/5 px-3 py-3"
                        >
                            <span className="min-w-0 flex-1 text-lg font-bold leading-snug">{row.name}</span>
                            <span dir="ltr" className="text-3xl font-black tabular-nums text-amber-300">
                                {formatQty(row.quantity)}
                            </span>
                        </div>
                    ))
                )}
            </div>
        </aside>
    );
}
