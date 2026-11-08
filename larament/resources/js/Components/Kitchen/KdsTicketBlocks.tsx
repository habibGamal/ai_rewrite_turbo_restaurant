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
    GlobalOutlined,
    HomeOutlined,
    MoreOutlined,
    ShopOutlined,
    ShoppingCartOutlined,
    ShoppingOutlined,
    TruckOutlined,
} from '@ant-design/icons';
import { Dropdown } from 'antd';
import { useState, type CSSProperties, type ReactNode } from 'react';

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

export default function KdsBlock({ ticket, blockKey, measureKey }: KdsBlockProps) {
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
}

function HeaderBlock({ ticket, measureKey }: { ticket: KdsTicket; measureKey?: string }) {
    const { nowMs, scale, kdsSettings, acknowledge } = useKds();
    const cancelled = ticket.status === 'cancelled';
    const urgency = ticketUrgency(ticket, nowMs, kdsSettings.warning_percent);
    const place = ticketPlaceLabel(ticket);
    const openElapsed = openRoundElapsedSeconds(ticket, nowMs);
    const elapsed = openElapsed ?? kitchenElapsedSeconds(ticket, nowMs);
    const timerColor = cancelled
        ? kdsTokens.textDim
        : urgency === 'late'
          ? kdsTokens.timerLate
          : urgency === 'warning'
            ? kdsTokens.timerWarning
            : kdsTokens.timer;
    const needsAck = ticketNeedsAttention(ticket);
    const timerText = formatDuration(elapsed);

    return (
        <div data-measure-key={measureKey} className="flex w-full items-start gap-1 px-2.5 py-2">
            <button
                type="button"
                className="flex min-w-0 flex-1 items-start gap-2 border-0 bg-transparent p-0 text-start text-inherit"
                onClick={() => acknowledge(ticket)}
                aria-label={needsAck ? `تم الاطلاع على الطلب ${ticket.order_number}` : `طلب ${ticket.order_number}`}
            >
                <div className="min-w-0 flex-1">
                    <div
                        dir="ltr"
                        className="text-end font-black leading-none tabular-nums"
                        style={{ fontSize: kdsPx(40, scale) }}
                    >
                        #{ticket.order_number}
                    </div>
                    <div
                        className="mt-1 flex flex-wrap items-center gap-1.5 font-bold"
                        style={{ fontSize: kdsPx(16, scale), color: kdsTokens.textSoft }}
                    >
                        {typeIcon(ticket.order_type)}
                        <span>{ticket.order_type_label}</span>
                        {ticket.seen_at == null && <NewBadge scale={scale} />}
                        {ticket.is_rush && <RushBadge scale={scale} />}
                    </div>
                    {place && (
                        <div className="mt-0.5 truncate font-black" style={{ fontSize: kdsPx(20, scale) }}>
                            {place}
                        </div>
                    )}
                </div>
                <div className="shrink-0 text-end">
                    <div
                        dir="ltr"
                        className={`font-black leading-none tabular-nums ${urgency === 'late' && !cancelled ? 'kds-late-timer' : ''}`}
                        style={{
                            fontSize: kdsPx(34, scale),
                            color: timerColor,
                            minWidth: timerText.length > 5 ? '8ch' : '5.6ch',
                        }}
                    >
                        {timerText}
                    </div>
                    {ticket.open_round_target_minutes != null && !cancelled && (
                        <div
                            className="mt-1 font-bold tabular-nums"
                            style={{ fontSize: kdsPx(15, scale), color: kdsTokens.textSoft }}
                        >
                            الهدف {ticket.open_round_target_minutes}د
                        </div>
                    )}
                </div>
            </button>
            {!cancelled && <OverflowMenu ticket={ticket} />}
        </div>
    );
}

function NewBadge({ scale }: { scale: number }) {
    return (
        <span
            className="rounded px-1.5 py-0.5 font-black leading-none"
            style={{ background: kdsTokens.fresh, color: kdsTokens.freshInk, fontSize: kdsPx(12, scale) }}
        >
            جديد
        </span>
    );
}

function RushBadge({ scale }: { scale: number }) {
    return (
        <span
            className="rounded px-1.5 py-0.5 font-black leading-none text-white"
            style={{ background: kdsTokens.rush, fontSize: kdsPx(12, scale) }}
        >
            عاجل
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
                    className="min-w-[180px] rounded-xl p-1 shadow-2xl"
                    style={{ background: kdsTokens.cardAlt, border: `1px solid ${kdsTokens.line}` }}
                >
                    <button
                        type="button"
                        className="kds-press min-h-12 w-full rounded-lg border-0 bg-transparent px-3 text-start text-base font-black"
                        style={{ color: kdsTokens.text }}
                        onClick={() => {
                            setOpen(false);
                            toggleRush(ticket);
                        }}
                    >
                        {ticket.is_rush ? 'إلغاء العاجل' : 'تعليم كعاجل'}
                    </button>
                </div>
            )}
        >
            <button
                type="button"
                aria-label="المزيد"
                aria-haspopup="menu"
                className="kds-press flex h-12 w-12 shrink-0 items-center justify-center rounded-lg border-0"
                style={{ background: 'transparent', color: kdsTokens.textSoft }}
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
            className="px-3 py-2 text-center text-2xl font-black text-white"
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
        <div
            data-measure-key={measureKey}
            className="px-3 py-2"
            style={{
                background: 'rgba(251, 191, 36, 0.1)',
                boxShadow: `inset -3px 0 0 ${kdsTokens.ack}`,
            }}
        >
            <div className="text-xs font-black" style={{ color: kdsTokens.note }}>
                ملاحظات المطبخ
            </div>
            <div className="mt-0.5 whitespace-pre-wrap break-words font-black" style={{ fontSize: kdsPx(18, scale), color: kdsTokens.noteStrong }}>
                {ticket.kitchen_notes}
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
            className="flex w-full items-center gap-1.5 overflow-hidden whitespace-nowrap px-3 font-bold"
            style={{
                minHeight: 28,
                color: kdsTokens.textSoft,
                borderTop: `1px solid ${kdsTokens.line}`,
                fontSize: kdsPx(13, scale),
            }}
        >
            <span>إضافة {round.number}</span>
            <span aria-hidden>·</span>
            <span dir="ltr" className="tabular-nums">
                {formatTime(round.started_at)}
            </span>
            <span aria-hidden>·</span>
            <span dir="ltr" className="tabular-nums">
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
    const stationName = item.station_id == null ? null : kds.stationNames.get(item.station_id) ?? null;
    const showStation = kds.stationId == null && stationName;
    const reduced = item.quantity < item.original_quantity && item.status !== 'voided';
    const delta = item.original_quantity - item.quantity;
    const done = item.status === 'done';
    const voided = item.status === 'voided';
    const started = item.status === 'started';
    const stripe = item.needs_ack ? kdsTokens.ack : started ? kdsTokens.started : 'transparent';
    const nameColor = voided ? kdsTokens.textDim : done ? kdsTokens.textDim : kdsTokens.text;

    const body = (
        <>
            <span
                className="mt-1 inline-flex w-5 shrink-0 items-center justify-center font-black leading-none"
                style={{ color: kdsTokens.check, fontSize: kdsPx(18, kds.scale) }}
                aria-hidden
            >
                {done ? '✓' : ''}
            </span>
            <Quantity item={item} scale={kds.scale} voided={voided} done={done} />
            <div className="min-w-0 flex-1">
                <div
                    className="font-black leading-snug"
                    style={{
                        fontSize: kdsPx(22, kds.scale),
                        color: nameColor,
                        textDecoration: voided || done ? 'line-through' : undefined,
                        textDecorationThickness: '1px',
                        textDecorationColor: voided ? kdsTokens.voidTag : 'rgba(154,166,181,0.7)',
                    }}
                >
                    {item.product_name}
                </div>
                {item.notes && (
                    <div className="mt-0.5 font-bold leading-snug" style={{ color: kdsTokens.note, fontSize: kdsPx(16, kds.scale) }}>
                        {item.notes}
                    </div>
                )}
                <LineTags
                    item={item}
                    reduced={reduced}
                    delta={delta}
                    started={started}
                    voided={voided}
                    stationName={showStation ? stationName : null}
                    scale={kds.scale}
                />
            </div>
        </>
    );

    const style = {
        minHeight: kdsTokens.touch,
        boxShadow: `inset -3px 0 0 ${stripe}`,
        background: 'transparent',
    };
    const className = `flex w-full items-start gap-2 border-0 px-2 py-1.5 text-start text-inherit ${
        item.needs_ack ? 'kds-ack-once' : ''
    } ${action ? 'kds-press cursor-pointer' : ''}`;

    if (!action) {
        return (
            <div data-measure-key={measureKey} className={className} style={style}>
                {body}
            </div>
        );
    }

    const label =
        action === 'undo' ? `تراجع ${item.product_name}` : action === 'start' ? `بدء ${item.product_name}` : `تم ${item.product_name}`;

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
            className="mt-0.5 inline-flex shrink-0 items-center justify-center rounded-md font-black leading-none tabular-nums"
            style={{
                width: kdsPx(44, scale),
                minHeight: kdsPx(36, scale),
                background: voided || done ? 'transparent' : kdsTokens.qtyBg,
                color: voided || done ? kdsTokens.textDim : kdsTokens.qtyInk,
                fontSize: kdsPx(22, scale),
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
    started,
    voided,
    stationName,
    scale,
}: {
    item: KdsItem;
    reduced: boolean;
    delta: number;
    started: boolean;
    voided: boolean;
    stationName: string | null;
    scale: number;
}) {
    const tags: ReactNode[] = [];
    const size = { fontSize: kdsPx(13, scale) };
    if (item.kind === 'increased' && !voided) {
        tags.push(
            <Tag key="inc" style={{ ...size, color: kdsTokens.increase }}>
                +{formatQty(item.quantity)} جديد
            </Tag>,
        );
    }
    if (reduced) {
        tags.push(
            <Tag key="arrow" style={{ ...size, color: kdsTokens.textSoft }}>
                {formatQty(item.original_quantity)}→{formatQty(item.quantity)}
            </Tag>,
        );
        tags.push(
            <Tag key="red" style={{ ...size, color: kdsTokens.reduce }}>
                −{formatQty(delta)}
            </Tag>,
        );
    }
    if (item.voided_after_done) {
        tags.push(
            <Tag key="vad" style={{ ...size, color: kdsTokens.voidAfter }}>
                ملغي بعد التحضير
            </Tag>,
        );
    } else if (voided) {
        tags.push(
            <Tag key="void" style={{ ...size, color: kdsTokens.voidTag }}>
                ملغي
            </Tag>,
        );
    }
    if (started) {
        tags.push(
            <Tag key="started" style={{ ...size, color: kdsTokens.started }}>
                بدأ
            </Tag>,
        );
    }
    if (stationName) {
        tags.push(
            <Tag key="station" style={{ ...size, color: kdsTokens.textDim }}>
                {stationName}
            </Tag>,
        );
    }
    if (tags.length === 0) {
        return null;
    }
    return <div className="mt-1 flex flex-wrap items-center gap-1.5">{tags}</div>;
}

function Tag({ children, style }: { children: ReactNode; style: CSSProperties }) {
    return (
        <span className="font-black leading-none" style={style}>
            {children}
        </span>
    );
}

function FooterBlock({ ticket, measureKey }: { ticket: KdsTicket; measureKey?: string }) {
    const { scale, acknowledge, finishTicket } = useKds();
    const cancelled = ticket.status === 'cancelled';
    const needsAck = ticket.seen_at == null || ticket.items.some((item) => item.needs_ack) || cancelled;
    const font = Math.min(22, kdsPx(18, scale));

    return (
        <div
            data-measure-key={measureKey}
            className="flex w-full items-center gap-2 px-2"
            style={{ height: kdsTokens.footerHeight, borderTop: `1px solid ${kdsTokens.line}` }}
        >
            {needsAck && (
                <button
                    type="button"
                    className="kds-press h-12 shrink-0 whitespace-nowrap rounded-lg border-0 px-3 font-black"
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
                    className="kds-press h-12 min-w-0 flex-1 whitespace-nowrap rounded-lg border-0 font-black"
                    style={{ background: kdsTokens.done, color: kdsTokens.doneInk, fontSize: font }}
                    onClick={() => finishTicket(ticket)}
                >
                    تم
                </button>
            )}
        </div>
    );
}
