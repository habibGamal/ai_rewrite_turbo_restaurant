import { useKds } from '@/Components/Kitchen/kdsContext';
import {
    formatDuration,
    formatQty,
    formatTime,
    kitchenElapsedSeconds,
    lineAction,
    openRoundElapsedSeconds,
    roundElapsedSeconds,
    ticketPlaceLabel,
    ticketUrgency,
} from '@/Components/Kitchen/ticketModel';
import type { KdsItem, KdsTicket } from '@/types/kitchen';
import {
    CheckOutlined,
    FireOutlined,
    GlobalOutlined,
    HomeOutlined,
    ShopOutlined,
    ShoppingCartOutlined,
    ShoppingOutlined,
    TruckOutlined,
} from '@ant-design/icons';
import type { ReactNode } from 'react';

const TYPE_ICONS: Record<string, ReactNode> = {
    dine_in: <HomeOutlined />,
    takeaway: <ShoppingOutlined />,
    delivery: <TruckOutlined />,
    companies: <ShopOutlined />,
    talabat: <ShoppingCartOutlined />,
    web_delivery: <GlobalOutlined />,
    web_takeaway: <GlobalOutlined />,
};

function px(base: number, scale: number): number {
    return Math.round(base * scale);
}

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
    const { nowMs, scale, kdsSettings, toggleRush } = useKds();
    const cancelled = ticket.status === 'cancelled';
    const urgency = ticketUrgency(ticket, nowMs, kdsSettings.warning_percent);
    const background = cancelled
        ? '#991b1b'
        : urgency === 'late'
          ? '#dc2626'
          : urgency === 'warning'
            ? '#f59e0b'
            : '#27272a';
    const color = !cancelled && urgency === 'warning' ? '#111827' : '#ffffff';
    const place = ticketPlaceLabel(ticket);
    const kitchen = kitchenElapsedSeconds(ticket, nowMs);
    const openElapsed = openRoundElapsedSeconds(ticket, nowMs);
    const showRound = openElapsed != null && ticket.closed_rounds_seconds > 0;

    return (
        <div data-measure-key={measureKey} className="block w-full px-3 py-2" style={{ background, color }}>
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <span dir="ltr" className="font-black leading-none tabular-nums" style={{ fontSize: px(44, scale) }}>
                            #{ticket.order_number}
                        </span>
                        {ticket.is_rush && (
                            <span className="rounded-md bg-purple-600 px-2 py-1 text-base font-black text-white">عاجل</span>
                        )}
                        {ticket.seen_at == null && (
                            <span className="animate-pulse rounded-md bg-emerald-400 px-2 py-1 text-base font-black text-black">
                                جديد
                            </span>
                        )}
                    </div>
                    <div className="mt-1 flex flex-wrap items-center gap-2 font-bold" style={{ fontSize: px(20, scale) }}>
                        {typeIcon(ticket.order_type)}
                        <span>{ticket.order_type_label}</span>
                        {place && <span className="opacity-90">· {place}</span>}
                    </div>
                </div>
                <div className="text-end">
                    <div dir="ltr" className="font-black leading-none tabular-nums" style={{ fontSize: px(36, scale) }}>
                        {formatDuration(kitchen)}
                    </div>
                    {showRound && (
                        <div className="mt-1 font-bold" style={{ fontSize: px(16, scale) }}>
                            الجولة <span dir="ltr">{formatDuration(openElapsed)}</span>
                        </div>
                    )}
                    {ticket.open_round_target_minutes != null && !cancelled && (
                        <div className="font-semibold opacity-80" style={{ fontSize: px(14, scale) }}>
                            هدف {ticket.open_round_target_minutes} د
                        </div>
                    )}
                </div>
            </div>
            {cancelled ? (
                <div className="mt-2 text-2xl font-black">الطلب ملغي</div>
            ) : (
                <button
                    type="button"
                    onClick={() => toggleRush(ticket)}
                    className={`mt-2 min-h-12 rounded-lg px-3 font-black ${
                        ticket.is_rush ? 'bg-purple-700 text-white' : 'bg-black/25 text-current'
                    }`}
                    style={{ fontSize: px(16, scale) }}
                >
                    {ticket.is_rush ? 'إلغاء العاجل' : 'عاجل'}
                </button>
            )}
        </div>
    );
}

function NotesBlock({ ticket, measureKey }: { ticket: KdsTicket; measureKey?: string }) {
    const { scale } = useKds();
    if (!ticket.kitchen_notes) {
        return null;
    }
    return (
        <div data-measure-key={measureKey} className="block w-full bg-amber-400 px-3 py-3 text-black">
            <div className="mb-1 flex items-center gap-2 text-sm font-black">
                <FireOutlined />
                ملاحظات المطبخ
            </div>
            <div className="whitespace-pre-wrap break-words font-black" style={{ fontSize: px(22, scale) }}>
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
            className="flex w-full items-center justify-between gap-2 bg-amber-950 px-3 py-2 text-amber-100"
        >
            <span className="font-black" style={{ fontSize: px(20, scale) }}>
                إضافة {round.number} — <span dir="ltr">{formatTime(round.started_at)}</span>
            </span>
            <span dir="ltr" className="font-black tabular-nums" style={{ fontSize: px(22, scale) }}>
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
    const touch = Math.max(48, px(48, kds.scale));
    const reduced = item.quantity < item.original_quantity;
    const delta = item.original_quantity - item.quantity;

    const background = item.needs_ack
        ? '#78350f'
        : item.kind === 'increased'
          ? '#064e3b'
          : item.status === 'started'
            ? '#0c4a6e'
            : item.status === 'voided'
              ? '#1c1917'
              : 'transparent';

    const body = (
        <>
            <Quantity item={item} reduced={reduced} scale={kds.scale} />
            <div className="min-w-0 flex-1">
                <div
                    className={`font-black leading-snug ${item.status === 'voided' || item.voided_after_done ? 'line-through' : ''}`}
                    style={{ fontSize: px(26, kds.scale) }}
                >
                    {item.product_name}
                </div>
                <div className="mt-1 flex flex-wrap items-center gap-1">
                    {item.kind === 'increased' && (
                        <span className="rounded bg-emerald-400 px-2 py-0.5 font-black text-black" style={{ fontSize: px(18, kds.scale) }}>
                            +{formatQty(item.quantity)} جديد
                        </span>
                    )}
                    {reduced && (
                        <span className="rounded bg-red-600 px-2 py-0.5 font-black text-white" style={{ fontSize: px(18, kds.scale) }}>
                            −{formatQty(delta)}
                        </span>
                    )}
                    {item.status === 'voided' && !item.voided_after_done && (
                        <span className="font-black text-zinc-300" style={{ fontSize: px(18, kds.scale) }}>
                            ملغي
                        </span>
                    )}
                    {item.voided_after_done && (
                        <span className="font-black text-red-300" style={{ fontSize: px(18, kds.scale) }}>
                            ملغي بعد التحضير
                        </span>
                    )}
                    {item.status === 'started' && (
                        <span className="font-black text-sky-300" style={{ fontSize: px(16, kds.scale) }}>
                            بدأ
                        </span>
                    )}
                    {showStation && (
                        <span className="font-bold text-zinc-400" style={{ fontSize: px(14, kds.scale) }}>
                            {stationName}
                        </span>
                    )}
                </div>
                {item.notes && (
                    <div
                        className="mt-1 inline-block rounded bg-amber-400 px-2 py-1 font-black text-black"
                        style={{ fontSize: px(18, kds.scale) }}
                    >
                        {item.notes}
                    </div>
                )}
            </div>
            {item.status === 'done' && <CheckOutlined className="text-2xl text-emerald-400" />}
        </>
    );

    const className = `flex w-full items-start gap-3 border-b border-white/10 px-3 py-2 text-start ${
        item.status === 'done' ? 'opacity-45' : ''
    } ${item.needs_ack ? 'animate-pulse' : ''} ${action ? 'cursor-pointer active:bg-white/10' : ''}`;
    const style = {
        background,
        minHeight: touch,
        boxShadow: item.needs_ack ? 'inset 0 0 0 3px #fbbf24' : undefined,
    };

    if (!action) {
        return (
            <div data-measure-key={measureKey} className={className} style={style}>
                {body}
            </div>
        );
    }

    return (
        <button
            type="button"
            data-measure-key={measureKey}
            className={className}
            style={style}
            onClick={() => kds.tapItem(ticket, item)}
        >
            {body}
        </button>
    );
}

function Quantity({ item, reduced, scale }: { item: KdsItem; reduced: boolean; scale: number }) {
    const size = { fontSize: px(36, scale) };
    if (item.kind === 'increased') {
        return (
            <span dir="ltr" className="font-black leading-none tabular-nums text-emerald-300" style={size}>
                +{formatQty(item.quantity)}
            </span>
        );
    }
    if (reduced) {
        return (
            <span dir="ltr" className="font-black leading-none tabular-nums" style={size}>
                <span className="line-through opacity-60">{formatQty(item.original_quantity)}</span>
                <span className="mx-1">→</span>
                <span>{formatQty(item.quantity)}</span>
            </span>
        );
    }
    return (
        <span
            dir="ltr"
            className={`font-black leading-none tabular-nums ${item.status === 'voided' ? 'line-through opacity-60' : ''}`}
            style={size}
        >
            {formatQty(item.quantity)}
        </span>
    );
}

function FooterBlock({ ticket, measureKey }: { ticket: KdsTicket; measureKey?: string }) {
    const { scale, stationId, acknowledge, finishTicket } = useKds();
    const touch = Math.max(48, px(52, scale));
    const needsAck = ticket.seen_at == null || ticket.items.some((item) => item.needs_ack);

    return (
        <div data-measure-key={measureKey} className="block w-full bg-zinc-950 p-2">
            <div className="flex flex-col gap-2">
                {needsAck && (
                    <button
                        type="button"
                        className="w-full rounded-xl bg-amber-400 font-black text-black"
                        style={{ minHeight: touch, fontSize: px(22, scale) }}
                        onClick={() => acknowledge(ticket)}
                    >
                        تم الاطلاع
                    </button>
                )}
                <button
                    type="button"
                    className="w-full rounded-xl bg-emerald-600 font-black text-white"
                    style={{ minHeight: touch, fontSize: px(24, scale) }}
                    onClick={() => finishTicket(ticket)}
                >
                    تم
                    {stationId != null && (
                        <span className="block text-sm font-bold text-emerald-100">لهذه المحطة</span>
                    )}
                </button>
            </div>
        </div>
    );
}
