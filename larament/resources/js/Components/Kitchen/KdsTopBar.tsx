import { connectionLabel, type KitchenConnection } from '@/Components/Kitchen/hooks/useKitchenChannel';
import { kdsTokens } from '@/Components/Kitchen/kdsTokens';
import type { BoardCounts } from '@/Components/Kitchen/ticketModel';
import type { KdsStation } from '@/types/kitchen';
import {
    AppstoreOutlined,
    AudioMutedOutlined,
    CloseCircleFilled,
    DownOutlined,
    HistoryOutlined,
    SearchOutlined,
    SettingOutlined,
    SoundFilled,
} from '@ant-design/icons';
import { Dropdown } from 'antd';
import { memo, type FormEvent, type ReactNode } from 'react';

export type BoardFilter = 'all' | 'fresh' | 'warning' | 'late';

interface KdsTopBarProps {
    stations: KdsStation[];
    stationId: number | null;
    stationLabel: string;
    onStationId: (stationId: number | null) => void;
    pageIndex: number;
    pageCount: number;
    totalBoardCount: number;
    counts: BoardCounts;
    filter: BoardFilter;
    onFilterChange: (filter: BoardFilter) => void;
    query: string;
    onQueryChange: (value: string) => void;
    onSearch: () => void;
    allDayOpen: boolean;
    onToggleAllDay: () => void;
    recallCount: number;
    recallOpen: boolean;
    onToggleRecall: () => void;
    muted: boolean;
    onToggleMute: () => void;
    connection: KitchenConnection;
    clock: string;
    onOpenSettings: () => void;
}

const KdsTopBar = memo(function KdsTopBar(props: KdsTopBarProps) {
    const pageLabel = `${props.pageIndex + 1}/${Math.max(props.pageCount, 1)}`;
    const connected = props.connection === 'connected';
    const connecting = props.connection === 'connecting' || props.connection === 'initialized';
    const dot = connected ? kdsTokens.fresh : connecting ? kdsTokens.timerWarning : kdsTokens.danger;

    const onSubmit = (event: FormEvent): void => {
        event.preventDefault();
        props.onSearch();
    };

    const toggleFilter = (next: BoardFilter): void => {
        props.onFilterChange(props.filter === next ? 'all' : next);
    };

    return (
        <header
            className="flex shrink-0 items-center gap-2 overflow-x-auto overflow-y-hidden px-3"
            style={{
                height: kdsTokens.barHeight,
                background: kdsTokens.bar,
                borderBottom: `1px solid ${kdsTokens.lineStrong}`,
            }}
        >
            <StationSelector
                stations={props.stations}
                stationId={props.stationId}
                stationLabel={props.stationLabel}
                totalBoardCount={props.totalBoardCount}
                onStationId={props.onStationId}
            />

            <span
                className="shrink-0 rounded-lg px-2 py-1 text-xs font-black tabular-nums"
                style={{ background: 'rgba(255,255,255,0.04)', color: kdsTokens.textSoft }}
                aria-label={`صفحة ${props.pageIndex + 1} من ${Math.max(props.pageCount, 1)}`}
            >
                {pageLabel}
            </span>

            <div className="flex items-center gap-1.5" role="group" aria-label="تصفية الطلبات">
                <FilterPill
                    label="الكل"
                    value={props.totalBoardCount}
                    active={props.filter === 'all'}
                    tone="neutral"
                    onClick={() => props.onFilterChange('all')}
                />
                <FilterPill
                    label="جديد"
                    value={props.counts.fresh}
                    active={props.filter === 'fresh'}
                    tone="fresh"
                    onClick={() => toggleFilter('fresh')}
                />
                <FilterPill
                    label="تحذير"
                    value={props.counts.warning}
                    active={props.filter === 'warning'}
                    tone="warning"
                    onClick={() => toggleFilter('warning')}
                />
                <FilterPill
                    label="متأخر"
                    value={props.counts.late}
                    active={props.filter === 'late'}
                    tone="late"
                    pulse={props.counts.late > 0}
                    onClick={() => toggleFilter('late')}
                />
            </div>

            <form onSubmit={onSubmit} className="relative shrink-0">
                <SearchOutlined
                    className="pointer-events-none absolute top-1/2 -translate-y-1/2"
                    style={{ insetInlineStart: 12, color: kdsTokens.textDim }}
                />
                <input
                    inputMode="numeric"
                    autoComplete="off"
                    placeholder="رقم الطلب..."
                    aria-label="بحث برقم الطلب"
                    value={props.query}
                    onChange={(event) => props.onQueryChange(event.target.value)}
                    className="kds-search h-11 w-36 rounded-xl border-0 ps-9 pe-8 text-sm font-bold"
                    style={{
                        background: kdsTokens.cardAlt,
                        color: kdsTokens.text,
                        boxShadow: `inset 0 0 0 1px ${kdsTokens.line}`,
                    }}
                />
                {props.query && (
                    <button
                        type="button"
                        aria-label="مسح البحث"
                        onClick={() => props.onQueryChange('')}
                        className="absolute top-1/2 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-full border-0 bg-transparent"
                        style={{ insetInlineEnd: 4, color: kdsTokens.textDim }}
                    >
                        <CloseCircleFilled />
                    </button>
                )}
            </form>

            <div className="ms-auto flex shrink-0 items-center gap-1.5">
                <BarButton active={props.allDayOpen} onClick={props.onToggleAllDay} icon={<AppstoreOutlined />}>
                    الإجمالي
                </BarButton>
                <BarButton
                    active={props.recallOpen}
                    onClick={props.onToggleRecall}
                    icon={<HistoryOutlined />}
                    ariaLabel={`استرجاع، ${props.recallCount}`}
                >
                    <span>استرجاع</span>
                    <span
                        className="inline-flex min-w-[22px] items-center justify-center rounded-full px-1.5 py-0.5 text-xs font-black leading-none tabular-nums"
                        style={{
                            background: props.recallOpen ? 'rgba(0,0,0,0.28)' : kdsTokens.qtyBg,
                            color: props.recallOpen ? kdsTokens.doneInk : kdsTokens.textSoft,
                        }}
                    >
                        {props.recallCount}
                    </span>
                </BarButton>

                <button
                    type="button"
                    onClick={props.onToggleMute}
                    aria-label={props.muted ? 'تشغيل الصوت' : 'كتم الصوت'}
                    title={props.muted ? 'الصوت مكتوم — اضغط للتشغيل' : 'كتم الصوت'}
                    className="kds-press flex h-11 w-11 items-center justify-center rounded-xl border-0"
                    style={{
                        background: props.muted ? 'rgba(239, 68, 68, 0.16)' : kdsTokens.cardAlt,
                        color: props.muted ? kdsTokens.timerLate : kdsTokens.textSoft,
                        boxShadow: `inset 0 0 0 1px ${props.muted ? 'rgba(239,68,68,0.4)' : kdsTokens.line}`,
                    }}
                >
                    {props.muted ? <AudioMutedOutlined /> : <SoundFilled />}
                </button>

                <div
                    className="flex h-11 items-center gap-2 rounded-xl px-2.5"
                    style={{
                        background: kdsTokens.cardElevated,
                        boxShadow: `inset 0 0 0 1px ${kdsTokens.line}`,
                    }}
                    title={connectionLabel(props.connection)}
                    aria-label={connectionLabel(props.connection)}
                >
                    <span
                        className={`h-2.5 w-2.5 shrink-0 rounded-full ${connected ? '' : 'kds-late-timer'}`}
                        style={{ background: dot }}
                    />
                    <time dir="ltr" className="text-base font-black leading-none tabular-nums">
                        {props.clock}
                    </time>
                </div>

                <button
                    type="button"
                    onClick={props.onOpenSettings}
                    aria-label="إعدادات الشاشة"
                    className="kds-press flex h-11 w-11 items-center justify-center rounded-xl border-0"
                    style={{
                        background: kdsTokens.cardAlt,
                        color: kdsTokens.text,
                        boxShadow: `inset 0 0 0 1px ${kdsTokens.line}`,
                    }}
                >
                    <SettingOutlined />
                </button>
            </div>
        </header>
    );
});

export default KdsTopBar;

function StationSelector({
    stations,
    stationId,
    stationLabel,
    totalBoardCount,
    onStationId,
}: {
    stations: KdsStation[];
    stationId: number | null;
    stationLabel: string;
    totalBoardCount: number;
    onStationId: (stationId: number | null) => void;
}) {
    const badge = (
        <span
            className="inline-flex min-w-[24px] items-center justify-center rounded-md px-1.5 py-0.5 text-xs font-black tabular-nums"
            style={{ background: kdsTokens.qtyBg, color: kdsTokens.text }}
        >
            {totalBoardCount}
        </span>
    );

    if (stations.length === 0) {
        return (
            <div className="flex shrink-0 items-center gap-2 pe-1">
                <h1 className="max-w-[14rem] truncate text-base font-black leading-none">{stationLabel}</h1>
                {badge}
            </div>
        );
    }

    return (
        <Dropdown
            trigger={['click']}
            placement="bottomRight"
            popupRender={() => (
                <div
                    className="min-w-[220px] rounded-2xl p-1.5 shadow-2xl"
                    style={{
                        background: kdsTokens.cardElevated,
                        border: `1px solid ${kdsTokens.lineStrong}`,
                    }}
                >
                    <StationMenuItem active={stationId == null} onClick={() => onStationId(null)}>
                        كل المحطات
                    </StationMenuItem>
                    {stations.map((station) => (
                        <StationMenuItem
                            key={station.id}
                            active={stationId === station.id}
                            onClick={() => onStationId(station.id)}
                        >
                            {station.name}
                        </StationMenuItem>
                    ))}
                </div>
            )}
        >
            <button
                type="button"
                aria-label="تغيير المحطة"
                className="kds-press flex h-11 shrink-0 items-center gap-2 rounded-xl border-0 px-3"
                style={{
                    background: kdsTokens.cardAlt,
                    color: kdsTokens.text,
                    boxShadow: `inset 0 0 0 1px ${kdsTokens.line}`,
                }}
            >
                <span className="max-w-[12rem] truncate text-sm font-black leading-none">{stationLabel}</span>
                {badge}
                <DownOutlined className="text-[11px]" style={{ color: kdsTokens.textDim }} />
            </button>
        </Dropdown>
    );
}

function StationMenuItem({
    active,
    onClick,
    children,
}: {
    active: boolean;
    onClick: () => void;
    children: string;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="kds-press flex min-h-11 w-full items-center justify-between rounded-xl border-0 px-3 text-start text-sm font-black"
            style={{
                background: active ? kdsTokens.done : 'transparent',
                color: active ? kdsTokens.doneInk : kdsTokens.text,
            }}
        >
            <span>{children}</span>
            {active && <span>✓</span>}
        </button>
    );
}

function FilterPill({
    label,
    value,
    active,
    tone,
    pulse = false,
    onClick,
}: {
    label: string;
    value: number;
    active: boolean;
    tone: 'neutral' | 'fresh' | 'warning' | 'late';
    pulse?: boolean;
    onClick: () => void;
}) {
    const color =
        tone === 'fresh'
            ? kdsTokens.fresh
            : tone === 'warning'
              ? kdsTokens.timerWarning
              : tone === 'late'
                ? kdsTokens.timerLate
                : kdsTokens.textSoft;
    const quiet = value === 0 && !active;

    return (
        <button
            type="button"
            aria-pressed={active}
            onClick={onClick}
            className="kds-press inline-flex h-9 items-center gap-1.5 rounded-full border-0 px-3 text-xs font-black"
            style={{
                background: active ? `${color}30` : quiet ? 'transparent' : `${color}18`,
                color: quiet ? kdsTokens.textDim : color,
                boxShadow: active ? `inset 0 0 0 1.5px ${color}` : `inset 0 0 0 1px ${quiet ? kdsTokens.line : `${color}45`}`,
            }}
        >
            <span>{label}</span>
            <span className={`tabular-nums ${pulse ? 'kds-late-timer' : ''}`} style={{ minWidth: '1ch' }}>
                {value}
            </span>
        </button>
    );
}

function BarButton({
    active,
    onClick,
    children,
    icon,
    ariaLabel,
}: {
    active: boolean;
    onClick: () => void;
    children: ReactNode;
    icon?: ReactNode;
    ariaLabel?: string;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={ariaLabel}
            aria-pressed={active}
            className="kds-press inline-flex h-11 items-center gap-1.5 rounded-xl border-0 px-3 text-sm font-black"
            style={{
                background: active ? kdsTokens.done : kdsTokens.cardAlt,
                color: active ? kdsTokens.doneInk : kdsTokens.text,
                boxShadow: active ? 'none' : `inset 0 0 0 1px ${kdsTokens.line}`,
            }}
        >
            {icon}
            {children}
        </button>
    );
}
