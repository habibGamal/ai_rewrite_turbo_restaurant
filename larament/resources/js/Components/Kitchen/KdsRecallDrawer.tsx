import { canUndoItem, formatDuration, formatQty, ticketPlaceLabel } from '@/Components/Kitchen/ticketModel';
import type { KdsItem, KdsTicket } from '@/types/kitchen';
import { Drawer } from 'antd';
import { useEffect } from 'react';

interface KdsRecallDrawerProps {
    open: boolean;
    onClose: () => void;
    tickets: KdsTicket[];
    nowMs: number;
    recallMinutes: number;
    highlightedId: number | null;
    onUndo: (item: KdsItem) => void;
}

export default function KdsRecallDrawer({
    open,
    onClose,
    tickets,
    nowMs,
    recallMinutes,
    highlightedId,
    onUndo,
}: KdsRecallDrawerProps) {
    useEffect(() => {
        if (!open || highlightedId == null) {
            return;
        }
        document.querySelector(`[data-recall-ticket="${highlightedId}"]`)?.scrollIntoView({ block: 'center' });
    }, [open, highlightedId]);

    return (
        <Drawer
            title="استرجاع الطلبات"
            placement="left"
            open={open}
            onClose={onClose}
            width={460}
            styles={{ body: { padding: 12 } }}
        >
            <p className="mb-3 text-base font-bold text-zinc-300">
                يمكن التراجع خلال {recallMinutes} دقيقة من تجهيز الصنف.
            </p>
            {tickets.length === 0 ? (
                <div className="py-12 text-center text-xl font-bold text-zinc-400">لا توجد طلبات مسترجعة</div>
            ) : (
                <div className="flex flex-col gap-3">
                    {tickets.map((ticket) => (
                        <article
                            key={ticket.id}
                            data-recall-ticket={ticket.id}
                            className={`rounded-xl border-2 bg-zinc-900 p-3 ${
                                highlightedId === ticket.id ? 'border-yellow-300' : 'border-zinc-700'
                            }`}
                        >
                            <div className="mb-2 flex items-start justify-between gap-2">
                                <div>
                                    <div className="text-3xl font-black leading-none">#{ticket.order_number}</div>
                                    <div className="mt-1 text-base font-bold text-zinc-300">
                                        {ticket.order_type_label}
                                        {ticketPlaceLabel(ticket) ? ` · ${ticketPlaceLabel(ticket)}` : ''}
                                    </div>
                                </div>
                                <div dir="ltr" className="text-2xl font-black tabular-nums text-zinc-200">
                                    {formatDuration(ticket.closed_rounds_seconds)}
                                </div>
                            </div>
                            <div className="flex flex-col gap-2">
                                {ticket.items.map((item) => {
                                    const undo = canUndoItem(item, recallMinutes, nowMs);
                                    return (
                                        <div
                                            key={item.id}
                                            className="flex items-center gap-2 rounded-lg bg-zinc-800 px-2 py-2"
                                        >
                                            <span dir="ltr" className="w-12 text-center text-2xl font-black tabular-nums">
                                                {formatQty(item.quantity)}
                                            </span>
                                            <span className={`min-w-0 flex-1 text-lg font-bold ${item.status === 'voided' ? 'line-through text-zinc-500' : ''}`}>
                                                {item.product_name}
                                            </span>
                                            {undo ? (
                                                <button
                                                    type="button"
                                                    className="min-h-12 shrink-0 rounded-lg bg-red-600 px-3 font-black text-white"
                                                    onClick={() => onUndo(item)}
                                                >
                                                    تراجع
                                                </button>
                                            ) : (
                                                <span className="text-sm font-bold text-zinc-500">
                                                    {item.status === 'voided' ? 'ملغي' : 'انتهت المهلة'}
                                                </span>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        </article>
                    ))}
                </div>
            )}
        </Drawer>
    );
}
