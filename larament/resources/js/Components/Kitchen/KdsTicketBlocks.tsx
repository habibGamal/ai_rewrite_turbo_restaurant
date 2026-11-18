import { useKds } from '@/Components/Kitchen/kdsContext';
import { kdsPx, kdsTokens } from '@/Components/Kitchen/kdsTokens';
import {
    formatDuration,
    formatQty,
    formatTime,
    kitchenElapsedSeconds,
    lineAction,
    openRoundElapsedSeconds,
    roundElapsedSeconds,
    ticketNeedsAttention,
    ticketPlaceLabel,
    ticketUrgency,
} from '@/Components/Kitchen/ticketModel';
import type { KdsItem, KdsTicket } from '@/types/kitchen';
import {
    CheckOutlined,
    FireFilled,
    GlobalOutlined,
    HomeOutlined,
    MoreOutlined,
    ShopOutlined,
    ShoppingCartOutlined,
    ShoppingOutlined,
    TruckOutlined,
} from '@ant-design/icons';
import { Dropdown } from 'antd';
import { memo, useState, type CSSProperties, type ReactNode } from 'react';

const TYPE_ICONS: Record<string, ReactNode> = {
    dine_in: <HomeOutlined />,
    takeaway: <ShoppingOutlined />,
    delivery: <TruckOutlined />,
    companies: <ShopOutlined />,
    talabat: <ShoppingCartOutlined />,
    web_delivery: <GlobalOutlined />,
    web_takeaway: <GlobalOutlined />,
};

function typeIcon(orderType: string): ReactNode {
    return Object.hasOwn(TYPE_ICONS, orderType) ? TYPE_ICONS[orderType] : null;
}

function parsePrefixedId(key: string, prefix: string): number | null {
    if (!key.startsWith(prefix)) {
        return null;
    }
    const id = Number(key.slice(prefix.length));
    return Number.isFinite(id) ? id : null;
}

interface KdsBlockProps {
    ticket: KdsTicket;
    blockKey: string;
    measureKey?: string;
}

const KdsBlock = memo(function KdsBlock({ ticket, blockKey, measureKey }: KdsBlockProps) {
    if (blockKey === 'header') {
        return <HeaderBlock ticket={ticket} measureKey={measureKey} />;
    }
    if (blockKey === 'cancelled') {
        return <CancelledBanner measureKey={measureKey} />;
    }
    if (blockKey === 'notes') {
        return <NotesBlock ticket={ticket} measureKey={measureKey} />;
    }
    if (blockKey === 'footer') {
        return <FooterBlock ticket={ticket} measureKey={measureKey} />;
    }
    const roundId = parsePrefixedId(blockKey, 'round-');
    if (roundId != null) {
        return <RoundBlock ticket={ticket} roundId={roundId} measureKey={measureKey} />;
    }
    const itemId = parsePrefixedId(blockKey, 'line-');
    if (itemId != null) {
        return <LineBlock ticket={ticket} itemId={itemId} measureKey={measureKey} />;
    }
    return null;
});

export default KdsBlock;

function HeaderBlock({ ticket, measureKey }: { ticket: KdsTicket; measureKey?: string }) {
    const { nowMs, scale, kdsSettings, acknowledge } = useKds();
    const cancelled = ticket.status === 'cancelled';
    const urgency = ticketUrgency(ticket, nowMs, kdsSettings.warning_percent);
    const place = ticketPlaceLabel(ticket);
    const openElapsed = openRoundElapsedSeconds(ticket, nowMs);
    const elapsed = openElapsed ?? kitchenElapsedSeconds(ticket, nowMs);
    const targetSeconds =
        ticket.open_round_target_minutes != null ? ticket.open_round_target_minutes * 60 : null;
    const progressPercent =
        targetSeconds != null && targetSeconds > 0
            ? Math.min(100, Math.round((elapsed / targetSeconds) * 100))
            : targetSeconds === 0 && elapsed > 0
              ? 100
              : 0;

    const accent = cancelled
        ? kdsTokens.stripLate
        : urgency === 'late'
          ? kdsTokens.stripLate
          : urgency === 'warning'
            ? kdsTokens.stripWarning
            : kdsTokens.stripNormal;

    const headerBg = cancelled
        ? 'rgba(190, 18, 60, 0.16)'
        : urgency === 'late'
          ? 'rgba(239, 68, 68, 0.12)'
          : urgency === 'warning'
            ? 'rgba(245, 158, 11, 0.10)'
            : ticket.is_rush
              ? 'rgba(124, 58, 237, 0.12)'
              : 'rgba(255, 255, 255, 0.02)';

    const timerColor = cancelled
        ? kdsTokens.textDim
        : urgency === 'late'
          ? kdsTokens.timerLate
          : urgency === 'warning'
            ? kdsTokens.timerWarning
            : kdsTokens.timer;
    const needsAck = ticketNeedsAttention(ticket);
    const timerText = formatDuration(elapsed);
    const hasEditedAck = ticket.seen_at != null && ticket.items.some((item) => item.needs_ack);

    return (
        <div
            data-measure-key={measureKey}
            className="w-full"
            style={{ background: headerBg, borderBottom: `1px solid ${kdsTokens.line}` }}
        >
            <div className="flex w-full items-start gap-1.5 px-3 py-2.5">
                <button
                    type="button"
                    className="flex min-w-0 flex-1 items-start justify-between gap-2 border-0 bg-transparent p-0 text-start text-inherit"
                    onClick={() => acknowledge(ticket)}
                    aria-label={needsAck ? `تم الاطلاع على الطلب ${ticket.order_number}` : `طلب ${ticket.order_number}`}
                >
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-1.5">
                            <span
                                dir="ltr"
                                className="font-black leading-none tracking-tight tabular-nums"
                                style={{ fontSize: kdsPx(34, scale) }}
                            >
                                #{ticket.order_number}
                            </span>
                            {ticket.seen_at == null && !cancelled && <NewBadge scale={scale} />}
                            {ticket.is_rush && <RushBadge scale={scale} />}
                            {hasEditedAck && !cancelled && <EditedBadge scale={scale} />}
                        </div>

                        <div
                            className="mt-1.5 flex flex-wrap items-center gap-1.5 font-bold"
                            style={{ fontSize: kdsPx(14, scale), color: kdsTokens.textSoft }}
                        >
                            <span
                                className="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5"
                                style={{ background: 'rgba(255,255,255,0.06)' }}
                            >
                                {typeIcon(ticket.order_type)}
                                <span>{ticket.order_type_label}</span>
                            </span>
                            {place && (
                                <span
                                    className="truncate font-black"
                                    style={{ fontSize: kdsPx(17, scale), color: kdsTokens.text }}
                                >
                                    {place}
                                </span>
                            )}
                        </div>
                    </div>

                    <div className="shrink-0 text-end">
                        <div
                            dir="ltr"
                            className={`font-black leading-none tabular-nums ${urgency === 'late' && !cancelled ? 'kds-late-timer' : ''}`}
                            style={{
                                fontSize: kdsPx(30, scale),
                                color: timerColor,
                                minWidth: timerText.length > 5 ? '7.5ch' : '5ch',
                            }}
                        >
                            {timerText}
                        </div>
                        {ticket.open_round_target_minutes != null && !cancelled && (
                            <div
                                className="mt-1 font-bold tabular-nums"
                                style={{ fontSize: kdsPx(13, scale), color: kdsTokens.textDim }}
                            >
                                الهدف {ticket.open_round_target_minutes}د
                            </div>
                        )}
                    </div>
                </button>
                {!cancelled && <OverflowMenu ticket={ticket} />}
            </div>

            {!cancelled && targetSeconds != null && (
                <div className="h-1 w-full overflow-hidden" style={{ background: 'rgba(255,255,255,0.06)' }}>
                    <div
                        className="h-full transition-[width] duration-500 ease-out"
                        style={{
                            width: `${progressPercent}%`,
                            background: accent,
                        }}
                    />
                </div>
            )}
        </div>
    );
}

function NewBadge({ scale }: { scale: number }) {
    return (
        <span
            className="rounded-md px-1.5 py-0.5 font-black leading-none"
            style={{ background: kdsTokens.fresh, color: kdsTokens.freshInk, fontSize: kdsPx(11, scale) }}
        >
            جديد
        </span>
    );
}

function RushBadge({ scale }: { scale: number }) {
    return (
        <span
            className="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 font-black leading-none text-white"
            style={{ background: kdsTokens.rush, fontSize: kdsPx(11, scale) }}
        >
            <FireFilled />
            عاجل
        </span>
    );
}

function EditedBadge({ scale }: { scale: number }) {
    return (
        <span
            className="rounded-md px-1.5 py-0.5 font-black leading-none"
            style={{ background: kdsTokens.ack, color: kdsTokens.ackInk, fontSize: kdsPx(11, scale) }}
        >
            تعديل
        </span>
    );
}

function OverflowMenu({ ticket }: { ticket: KdsTicket }) {
    const { toggleRush } = useKds();
    const [open, setOpen] = useState(false);

    return (
        <Dropdown
            open={open}
            onOpenChange={setOpen}
            trigger={['click']}
            placement="bottomLeft"
            popupRender={() => (
                <div
                    className="min-w-[180px] rounded-2xl p-1.5 shadow-2xl"
                    style={{ background: kdsTokens.cardElevated, border: `1px solid ${kdsTokens.lineStrong}` }}
                >
                    <button
                        type="button"
                        className="kds-press flex min-h-11 w-full items-center gap-2 rounded-xl border-0 bg-transparent px-3 text-start text-sm font-black"
                        style={{ color: kdsTokens.text }}
                        onClick={() => {
                            setOpen(false);
                            toggleRush(ticket);
                        }}
                    >
                        <FireFilled style={{ color: ticket.is_rush ? kdsTokens.textDim : '#a78bfa' }} />
                        <span>{ticket.is_rush ? 'إلغاء العاجل' : 'تعليم كعاجل'}</span>
                    </button>
                </div>
            )}
        >
            <button
                type="button"
                aria-label="المزيد"
                aria-haspopup="menu"
                className="kds-press -me-1 flex h-10 w-9 shrink-0 items-center justify-center rounded-xl border-0"
                style={{ background: 'rgba(255,255,255,0.04)', color: kdsTokens.textSoft }}
                onClick={(event) => event.stopPropagation()}
            >
                <MoreOutlined />
            </button>
        </Dropdown>
    );
}

function CancelledBanner({ measureKey }: { measureKey?: string }) {
    return (
        <div
            data-measure-key={measureKey}
            className="px-3 py-2 text-center text-xl font-black text-white"
            style={{ background: kdsTokens.cancelledBanner }}
        >
            الطلب ملغي
        </div>
    );
}

function NotesBlock({ ticket, measureKey }: { ticket: KdsTicket; measureKey?: string }) {
    const { scale } = useKds();
    if (!ticket.kitchen_notes) {
        return null;
    }
    return (
        <div data-measure-key={measureKey} className="px-2.5 pt-2 pb-1">
            <div
                className="rounded-xl px-3 py-2"
                style={{
                    background: 'rgba(251, 191, 36, 0.12)',
                    border: '1px solid rgba(251, 191, 36, 0.3)',
                    boxShadow: `inset -3px 0 0 ${kdsTokens.ack}`,
                }}
            >
                <div className="text-xs font-black" style={{ color: kdsTokens.note }}>
                    ملاحظات المطبخ
                </div>
                <div
                    className="mt-0.5 whitespace-pre-wrap break-words font-black leading-snug"
                    style={{ fontSize: kdsPx(16, scale), color: kdsTokens.noteStrong }}
                >
                    {ticket.kitchen_notes}
                </div>
            </div>
        </div>
    );
}

function RoundBlock({
    ticket,
    roundId,
    measureKey,
}: {
    ticket: KdsTicket;
    roundId: number;
    measureKey?: string;
}) {
    const { nowMs, scale } = useKds();
    const round = ticket.rounds.find((entry) => entry.id === roundId);
    if (!round) {
        return null;
    }
    return (
        <div
            data-measure-key={measureKey}
            className="flex w-full items-center justify-between gap-2 overflow-hidden whitespace-nowrap px-3 py-1.5 font-bold"
            style={{
                background: 'rgba(255, 255, 255, 0.03)',
                borderTop: `1px solid ${kdsTokens.line}`,
                borderBottom: `1px solid ${kdsTokens.line}`,
                color: kdsTokens.textSoft,
                fontSize: kdsPx(12, scale),
            }}
        >
            <span className="flex items-center gap-1.5">
                <span className="h-1.5 w-1.5 rounded-full" style={{ background: kdsTokens.ack }} />
                <span>إضافة {round.number}</span>
                <span aria-hidden>·</span>
                <span dir="ltr" className="tabular-nums" style={{ color: kdsTokens.textDim }}>
                    {formatTime(round.started_at)}
                </span>
            </span>
            <span dir="ltr" className="tabular-nums" style={{ color: kdsTokens.timerWarning }}>
                {formatDuration(roundElapsedSeconds(round, nowMs))}
            </span>
        </div>
    );
}

function LineBlock({
    ticket,
    itemId,
    measureKey,
}: {
    ticket: KdsTicket;
    itemId: number;
    measureKey?: string;
}) {
    const kds = useKds();
    const item = ticket.items.find((entry) => entry.id === itemId);
    if (!item) {
        return null;
    }
    const action = lineAction(ticket, item, kds.kdsSettings, kds.nowMs);
    const stationName = item.station_id == null ? null : (kds.stationNames.get(item.station_id) ?? null);
    const showStation = kds.stationId == null && stationName;
    const reduced = item.quantity < item.original_quantity && item.status !== 'voided';
    const delta = item.original_quantity - item.quantity;
    const done = item.status === 'done';
    const voided = item.status === 'voided';
    const stripe = item.needs_ack ? kdsTokens.ack : 'transparent';
    const nameColor = voided || done ? kdsTokens.textDim : kdsTokens.text;

    const body = (
        <>
            <span
                className="mt-1 flex h-6 w-6 shrink-0 items-center justify-center rounded-lg text-xs font-black"
                style={{
                    background: done
                        ? 'rgba(74, 222, 128, 0.16)'
                        : voided
                          ? 'rgba(156, 163, 175, 0.12)'
                          : 'rgba(255, 255, 255, 0.05)',
                    border: `1.5px solid ${done ? kdsTokens.check : voided ? kdsTokens.voidTag : kdsTokens.lineStrong}`,
                    color: done ? kdsTokens.check : kdsTokens.textDim,
                }}
                aria-hidden
            >
                {done ? <CheckOutlined /> : voided ? '×' : ''}
            </span>

            <Quantity item={item} scale={kds.scale} voided={voided} done={done} />

            <div className="min-w-0 flex-1">
                <div
                    className="font-black leading-snug"
                    style={{
                        fontSize: kdsPx(20, kds.scale),
                        color: nameColor,
                        textDecoration: voided || done ? 'line-through' : undefined,
                        textDecorationThickness: '1.5px',
                        textDecorationColor: voided ? kdsTokens.voidTag : 'rgba(148, 163, 184, 0.7)',
                    }}
                >
                    {item.product_name}
                </div>
                {item.notes && (
                    <div
                        className="mt-0.5 font-bold leading-snug"
                        style={{ color: kdsTokens.note, fontSize: kdsPx(15, kds.scale) }}
                    >
                        {item.notes}
                    </div>
                )}
                <LineTags
                    item={item}
                    reduced={reduced}
                    delta={delta}
                    voided={voided}
                    stationName={showStation ? stationName : null}
                    scale={kds.scale}
                />
            </div>
        </>
    );

    const style: CSSProperties = {
        minHeight: kdsTokens.touch,
        boxShadow: `inset -3px 0 0 ${stripe}`,
        borderBottom: `1px solid rgba(255, 255, 255, 0.05)`,
        background: done ? 'rgba(22, 163, 74, 0.04)' : voided ? 'rgba(0, 0, 0, 0.14)' : 'transparent',
    };
    const className = `flex w-full items-start gap-2.5 border-0 px-3 py-2 text-start text-inherit ${
        item.needs_ack ? 'kds-ack-once' : ''
    } ${action ? 'kds-press cursor-pointer hover:bg-white/[0.04]' : ''}`;

    if (!action) {
        return (
            <div data-measure-key={measureKey} className={className} style={style}>
                {body}
            </div>
        );
    }

    const label = action === 'undo' ? `تراجع ${item.product_name}` : `تم ${item.product_name}`;

    return (
        <button
            type="button"
            data-measure-key={measureKey}
            className={className}
            style={style}
            aria-label={label}
            onClick={() => kds.tapItem(ticket, item)}
        >
            {body}
        </button>
    );
}

function Quantity({
    item,
    scale,
    voided,
    done,
}: {
    item: KdsItem;
    scale: number;
    voided: boolean;
    done: boolean;
}) {
    return (
        <span
            dir="ltr"
            className="mt-0.5 inline-flex shrink-0 items-center justify-center rounded-lg font-black leading-none tabular-nums"
            style={{
                minWidth: kdsPx(40, scale),
                height: kdsPx(34, scale),
                paddingInline: 6,
                background: voided || done ? 'transparent' : kdsTokens.qtyBg,
                color: voided || done ? kdsTokens.textDim : kdsTokens.qtyInk,
                fontSize: kdsPx(19, scale),
                boxShadow: voided || done ? `inset 0 0 0 1px ${kdsTokens.line}` : undefined,
            }}
        >
            {formatQty(item.quantity)}
        </span>
    );
}

function LineTags({
    item,
    reduced,
    delta,
    voided,
    stationName,
    scale,
}: {
    item: KdsItem;
    reduced: boolean;
    delta: number;
    voided: boolean;
    stationName: string | null;
    scale: number;
}) {
    const tags: ReactNode[] = [];
    const size = { fontSize: kdsPx(12, scale) };
    if (item.kind === 'increased' && !voided) {
        tags.push(
            <Tag key="inc" style={{ ...size, color: kdsTokens.increase, background: 'rgba(110, 231, 183, 0.12)' }}>
                +{formatQty(item.quantity)} جديد
            </Tag>,
        );
    }
    if (reduced) {
        tags.push(
            <Tag key="arrow" style={{ ...size, color: kdsTokens.textSoft, background: 'rgba(255,255,255,0.06)' }}>
                {formatQty(item.original_quantity)}→{formatQty(item.quantity)}
            </Tag>,
        );
        tags.push(
            <Tag key="red" style={{ ...size, color: kdsTokens.reduce, background: 'rgba(252, 165, 165, 0.12)' }}>
                −{formatQty(delta)}
            </Tag>,
        );
    }
    if (item.voided_after_done) {
        tags.push(
            <Tag key="vad" style={{ ...size, color: kdsTokens.voidAfter, background: 'rgba(248, 113, 113, 0.14)' }}>
                ملغي بعد التحضير
            </Tag>,
        );
    } else if (voided) {
        tags.push(
            <Tag key="void" style={{ ...size, color: kdsTokens.voidTag, background: 'rgba(156, 163, 175, 0.12)' }}>
                ملغي
            </Tag>,
        );
    }
    if (stationName) {
        tags.push(
            <Tag key="station" style={{ ...size, color: kdsTokens.textDim, background: 'rgba(255,255,255,0.05)' }}>
                {stationName}
            </Tag>,
        );
    }
    if (tags.length === 0) {
        return null;
    }
    return <div className="mt-1.5 flex flex-wrap items-center gap-1.5">{tags}</div>;
}

function Tag({ children, style }: { children: ReactNode; style: CSSProperties }) {
    return (
        <span className="rounded-md px-1.5 py-0.5 font-black leading-none" style={style}>
            {children}
        </span>
    );
}

function FooterBlock({ ticket, measureKey }: { ticket: KdsTicket; measureKey?: string }) {
    const { scale, kdsSettings, acknowledge, finishTicket } = useKds();
    const cancelled = ticket.status === 'cancelled';
    const needsAck = ticket.seen_at == null || ticket.items.some((item) => item.needs_ack) || cancelled;
    const font = Math.min(20, kdsPx(17, scale));
    const activeItems = ticket.items.filter((item) => item.status !== 'voided');
    const doneCount = activeItems.filter((item) => item.status === 'done').length;
    const totalActiveCount = activeItems.length;
    const showProgress = kdsSettings.item_tracking && !cancelled && totalActiveCount > 0;

    return (
        <div
            data-measure-key={measureKey}
            className="flex w-full flex-col gap-1.5 px-2.5 py-2"
            style={{
                background: 'rgba(0, 0, 0, 0.18)',
                borderTop: `1px solid ${kdsTokens.line}`,
            }}
        >
            {showProgress && (
                <div className="flex items-center justify-between px-1 text-xs font-bold" style={{ color: kdsTokens.textDim }}>
                    <span>تجهيز الأصناف</span>
                    <span
                        dir="ltr"
                        className="tabular-nums"
                        style={{ color: doneCount === totalActiveCount ? kdsTokens.check : kdsTokens.textSoft }}
                    >
                        {doneCount} / {totalActiveCount}
                    </span>
                </div>
            )}
            <div className="flex items-center gap-2">
                {needsAck && (
                    <button
                        type="button"
                        className="kds-press h-12 shrink-0 whitespace-nowrap rounded-xl border-0 px-3.5 font-black"
                        style={{
                            background: kdsTokens.ack,
                            color: kdsTokens.ackInk,
                            fontSize: font,
                            minWidth: cancelled ? undefined : 112,
                            flex: cancelled ? '1 1 auto' : undefined,
                        }}
                        onClick={() => acknowledge(ticket)}
                    >
                        تم الاطلاع
                    </button>
                )}
                {!cancelled && (
                    <button
                        type="button"
                        className="kds-press h-12 min-w-0 flex-1 whitespace-nowrap rounded-xl border-0 font-black"
                        style={{ background: kdsTokens.done, color: kdsTokens.doneInk, fontSize: font }}
                        onClick={() => finishTicket(ticket)}
                    >
                        تم
                    </button>
                )}
            </div>
        </div>
    );
}
