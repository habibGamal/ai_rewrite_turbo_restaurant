import { kdsTokens } from '@/Components/Kitchen/kdsTokens';
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
    onUndo: (item: KdsItem, ticket: KdsTicket) => void;
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
            width={440}
            styles={{ body: { padding: 12, background: kdsTokens.page } }}
        >
            <p className="mb-3 text-base font-bold" style={{ color: kdsTokens.textSoft }}>
                يمكن التراجع خلال {recallMinutes} دقيقة من تجهيز الصنف.
            </p>
            {tickets.length === 0 ? (
                <div className="py-12 text-center text-xl font-bold" style={{ color: kdsTokens.textDim }}>
                    لا توجد طلبات مسترجعة
                </div>
            ) : (
                <div className="flex flex-col gap-3">
                    {tickets.map((ticket) => (
                        <article
                            key={ticket.id}
                            data-recall-ticket={ticket.id}
                            className="rounded-xl p-3"
                            style={{
                                background: kdsTokens.card,
                                boxShadow:
                                    highlightedId === ticket.id
                                        ? `0 0 0 2px ${kdsTokens.highlight}`
                                        : `inset 0 0 0 1px ${kdsTokens.line}`,
                            }}
                        >
                            <div className="mb-2 flex items-start justify-between gap-2">
                                <div>
                                    <div className="text-3xl font-black leading-none tabular-nums">#{ticket.order_number}</div>
                                    <div className="mt-1 text-base font-bold" style={{ color: kdsTokens.textSoft }}>
                                        {ticket.order_type_label}
                                        {ticketPlaceLabel(ticket) ? ` · ${ticketPlaceLabel(ticket)}` : ''}
                                    </div>
                                </div>
                                <div dir="ltr" className="text-2xl font-black tabular-nums" style={{ color: kdsTokens.textSoft }}>
                                    {formatDuration(ticket.closed_rounds_seconds)}
                                </div>
                            </div>
                            <div className="flex flex-col gap-2">
                                {ticket.items.map((item) => {
                                    const undo = canUndoItem(item, recallMinutes, nowMs);
                                    return (
                                        <div
                                            key={item.id}
                                            className="flex items-center gap-2 rounded-lg px-2 py-1.5"
                                            style={{ background: kdsTokens.cardAlt }}
                                        >
                                            <span
                                                dir="ltr"
                                                className="inline-flex w-12 shrink-0 items-center justify-center text-2xl font-black tabular-nums"
                                            >
                                                {formatQty(item.quantity)}
                                            </span>
                                            <span
                                                className={`min-w-0 flex-1 text-lg font-bold ${item.status === 'voided' ? 'line-through' : ''}`}
                                                style={{ color: item.status === 'voided' ? kdsTokens.textDim : kdsTokens.text }}
                                            >
                                                {item.product_name}
                                            </span>
                                            {undo ? (
                                                <button
                                                    type="button"
                                                    className="kds-press min-h-12 shrink-0 rounded-lg border-0 px-3 font-black text-white"
                                                    style={{ background: kdsTokens.danger }}
                                                    onClick={() => onUndo(item, ticket)}
                                                >
                                                    تراجع
                                                </button>
                                            ) : (
                                                <span className="text-sm font-bold" style={{ color: kdsTokens.textDim }}>
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
