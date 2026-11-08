import { connectionLabel, type KitchenConnection } from '@/Components/Kitchen/hooks/useKitchenChannel';
import { kdsTokens } from '@/Components/Kitchen/kdsTokens';
import type { BoardCounts } from '@/Components/Kitchen/ticketModel';
import { SearchOutlined, SettingOutlined } from '@ant-design/icons';
import type { FormEvent, ReactNode } from 'react';

interface KdsTopBarProps {
    stationLabel: string;
    counts: BoardCounts;
    query: string;
    onQueryChange: (value: string) => void;
    onSearch: () => void;
    pageIndex: number;
    pageCount: number;
    allDayOpen: boolean;
    onToggleAllDay: () => void;
    recallCount: number;
    recallOpen: boolean;
    onToggleRecall: () => void;
    connection: KitchenConnection;
    clock: string;
    onOpenSettings: () => void;
}

export default function KdsTopBar(props: KdsTopBarProps) {
    const pageLabel = `${props.pageIndex + 1}/${Math.max(props.pageCount, 1)}`;
    const connected = props.connection === 'connected';
    const connecting = props.connection === 'connecting' || props.connection === 'initialized';
    const dot = connected ? kdsTokens.fresh : connecting ? kdsTokens.timerWarning : kdsTokens.danger;

    const onSubmit = (event: FormEvent): void => {
        event.preventDefault();
        props.onSearch();
    };

    return (
        <header
            className="flex shrink-0 items-center gap-2 overflow-x-auto overflow-y-hidden px-3"
            style={{
                height: kdsTokens.barHeight,
                background: kdsTokens.bar,
                borderBottom: `1px solid ${kdsTokens.line}`,
            }}
        >
            <div className="flex min-w-0 items-baseline gap-2 pe-1">
                <h1 className="max-w-[14rem] truncate text-[15px] font-black leading-none">{props.stationLabel}</h1>
                <span
                    className="shrink-0 text-sm font-bold tabular-nums"
                    style={{ color: kdsTokens.textDim }}
                    aria-label={`صفحة ${props.pageIndex + 1} من ${Math.max(props.pageCount, 1)}`}
                >
                    {pageLabel}
                </span>
            </div>

            <div className="flex items-center gap-1">
                <CountPill label="جديد" value={props.counts.fresh} tone="fresh" />
                <CountPill label="تحذير" value={props.counts.warning} tone="warning" />
                <CountPill label="متأخر" value={props.counts.late} tone="late" pulse={props.counts.late > 0} />
            </div>

            <form onSubmit={onSubmit} className="relative">
                <SearchOutlined
                    className="pointer-events-none absolute top-1/2 -translate-y-1/2"
                    style={{ insetInlineStart: 12, color: kdsTokens.textDim }}
                />
                <input
                    inputMode="numeric"
                    autoComplete="off"
                    placeholder="رقم الطلب"
                    aria-label="بحث برقم الطلب"
                    value={props.query}
                    onChange={(event) => props.onQueryChange(event.target.value)}
                    className="kds-search h-12 w-40 rounded-lg border-0 ps-9 pe-2 text-sm font-bold"
                    style={{ background: kdsTokens.cardAlt, color: kdsTokens.text }}
                />
            </form>

            <div className="ms-auto flex items-center gap-1.5">
                <BarButton active={props.allDayOpen} onClick={props.onToggleAllDay}>
                    الإجمالي
                </BarButton>
                <BarButton active={props.recallOpen} onClick={props.onToggleRecall} ariaLabel={`استرجاع، ${props.recallCount}`}>
                    استرجاع
                    <span className="tabular-nums" style={{ minWidth: '1ch' }}>
                        {props.recallCount}
                    </span>
                </BarButton>
                <span
                    className="flex h-12 w-8 items-center justify-center"
                    title={connectionLabel(props.connection)}
                    aria-label={connectionLabel(props.connection)}
                >
                    <span
                        className={`h-2.5 w-2.5 rounded-full ${connected ? '' : 'kds-late-timer'}`}
                        style={{ background: dot }}
                    />
                </span>
                <time
                    dir="ltr"
                    className="w-[5.6rem] text-center text-lg font-black leading-none tabular-nums"
                >
                    {props.clock}
                </time>
                <button
                    type="button"
                    onClick={props.onOpenSettings}
                    aria-label="إعدادات الشاشة"
                    className="kds-press flex h-12 w-12 items-center justify-center rounded-lg border-0"
                    style={{ background: kdsTokens.cardAlt, color: kdsTokens.text }}
                >
                    <SettingOutlined />
                </button>
            </div>
        </header>
    );
}

function CountPill({
    label,
    value,
    tone,
    pulse = false,
}: {
    label: string;
    value: number;
    tone: 'fresh' | 'warning' | 'late';
    pulse?: boolean;
}) {
    const color =
        tone === 'fresh' ? kdsTokens.fresh : tone === 'warning' ? kdsTokens.timerWarning : kdsTokens.timerLate;
    const quiet = value === 0;
    return (
        <span
            className="inline-flex h-7 items-center gap-1 rounded-full px-2 text-xs font-black"
            style={{
                background: quiet ? 'transparent' : `${color}22`,
                color: quiet ? kdsTokens.textDim : color,
                border: `1px solid ${quiet ? kdsTokens.line : `${color}55`}`,
            }}
        >
            <span>{label}</span>
            <span className={`tabular-nums ${pulse ? 'kds-late-timer' : ''}`} style={{ minWidth: '1ch' }}>
                {value}
            </span>
        </span>
    );
}

function BarButton({
    active,
    onClick,
    children,
    ariaLabel,
}: {
    active: boolean;
    onClick: () => void;
    children: ReactNode;
    ariaLabel?: string;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={ariaLabel}
            aria-pressed={active}
            className="kds-press inline-flex h-12 items-center gap-1.5 rounded-lg border-0 px-3 text-sm font-black"
            style={{
                background: active ? kdsTokens.done : kdsTokens.cardAlt,
                color: active ? kdsTokens.doneInk : kdsTokens.text,
            }}
        >
            {children}
        </button>
    );
}
