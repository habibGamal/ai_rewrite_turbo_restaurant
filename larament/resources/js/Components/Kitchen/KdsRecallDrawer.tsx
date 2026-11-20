import { kdsTokens } from '@/Components/Kitchen/kdsTokens';
import {
    canUndoItem,
    formatDuration,
    formatQty,
    formatTime,
    ticketPlaceLabel,
} from '@/Components/Kitchen/ticketModel';
import type { KdsItem, KdsTicket } from '@/types/kitchen';
import { UndoOutlined } from '@ant-design/icons';
import { Drawer } from 'antd';
import { memo, useEffect } from 'react';

interface KdsRecallDrawerProps {
    open: boolean;
    onClose: () => void;
    tickets: KdsTicket[];
    nowMs: number;
    recallMinutes: number;
    highlightedId: number | null;
    onUndo: (item: KdsItem, ticket: KdsTicket) => void;
}

const KdsRecallDrawer = memo(function KdsRecallDrawer({
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
        document
            .querySelector(`[data-recall-ticket="${highlightedId}"]`)
            ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, [open, highlightedId]);

    return (
        <Drawer
            title="استرجاع الطلبات"
            placement="left"
            open={open}
            onClose={onClose}
            width={440}
            styles={{
                header: { background: kdsTokens.bar, borderBottom: `1px solid ${kdsTokens.line}` },
                body: { padding: 14, background: kdsTokens.page },
            }}
        >
            <p className="mb-3 text-sm font-bold" style={{ color: kdsTokens.textDim }}>
                يمكن التراجع عن تجهيز الأصناف خلال {recallMinutes} دقيقة من إتمامها.
            </p>
            {tickets.length === 0 ? (
                <div className="py-16 text-center text-lg font-bold" style={{ color: kdsTokens.textDim }}>
                    لا توجد طلبات منتهية ضمن مدة الاسترجاع
                </div>
            ) : (
                <div className="flex flex-col gap-3">
                    {tickets.map((ticket) => (
                        <article
                            key={ticket.id}
                            data-recall-ticket={ticket.id}
                            className="rounded-2xl p-3"
                            style={{
                                background: kdsTokens.card,
                                boxShadow:
                                    highlightedId === ticket.id
                                        ? `0 0 0 2px ${kdsTokens.highlight}`
                                        : `inset 0 0 0 1px ${kdsTokens.lineStrong}`,
                            }}
                        >
                            <div className="mb-2.5 flex items-start justify-between gap-2">
                                <div>
                                    <div className="flex items-center gap-2">
                                        <span dir="ltr" className="text-2xl font-black leading-none tabular-nums">
                                            #{ticket.order_number}
                                        </span>
                                        {ticket.done_at && (
                                            <span
                                                dir="ltr"
                                                className="rounded-md px-1.5 py-0.5 text-xs font-bold tabular-nums"
                                                style={{ background: kdsTokens.cardAlt, color: kdsTokens.textDim }}
                                            >
                                                {formatTime(ticket.done_at)}
                                            </span>
                                        )}
                                    </div>
                                    <div className="mt-1 text-sm font-bold" style={{ color: kdsTokens.textSoft }}>
                                        {ticket.order_type_label}
                                        {ticketPlaceLabel(ticket) ? ` · ${ticketPlaceLabel(ticket)}` : ''}
                                    </div>
                                </div>
                                <div className="text-end">
                                    <div dir="ltr" className="text-xl font-black tabular-nums" style={{ color: kdsTokens.check }}>
                                        {formatDuration(ticket.closed_rounds_seconds)}
                                    </div>
                                    <div className="text-[11px] font-bold" style={{ color: kdsTokens.textDim }}>
                                        مدة التحضير
                                    </div>
                                </div>
                            </div>
                            <div className="flex flex-col gap-1.5">
                                {ticket.items.map((item) => {
                                    const undo = canUndoItem(item, recallMinutes, nowMs);
                                    return (
                                        <div
                                            key={item.id}
                                            className="flex items-center gap-2 rounded-xl px-2.5 py-2"
                                            style={{ background: kdsTokens.cardAlt }}
                                        >
                                            <span
                                                dir="ltr"
                                                className="inline-flex min-w-[36px] shrink-0 items-center justify-center rounded-lg px-1.5 py-1 text-lg font-black tabular-nums"
                                                style={{ background: kdsTokens.qtyBg, color: kdsTokens.text }}
                                            >
                                                {formatQty(item.quantity)}
                                            </span>
                                            <span
                                                className={`min-w-0 flex-1 text-base font-bold ${item.status === 'voided' ? 'line-through' : ''}`}
                                                style={{ color: item.status === 'voided' ? kdsTokens.textDim : kdsTokens.text }}
                                            >
                                                {item.product_name}
                                            </span>
                                            {undo ? (
                                                <button
                                                    type="button"
                                                    className="kds-press inline-flex min-h-11 shrink-0 items-center gap-1.5 rounded-xl border-0 px-3 text-sm font-black text-white"
                                                    style={{ background: kdsTokens.danger }}
                                                    onClick={() => onUndo(item, ticket)}
                                                >
                                                    <UndoOutlined />
                                                    <span>تراجع</span>
                                                </button>
                                            ) : (
                                                <span className="text-xs font-bold" style={{ color: kdsTokens.textDim }}>
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
});

export default KdsRecallDrawer;
