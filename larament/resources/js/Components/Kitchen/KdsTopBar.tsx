import { connectionLabel, type KitchenConnection } from '@/Components/Kitchen/hooks/useKitchenChannel';
import type { BoardCounts } from '@/Components/Kitchen/ticketModel';
import {
    SearchOutlined,
    SettingOutlined,
    UnorderedListOutlined,
    RollbackOutlined,
} from '@ant-design/icons';
import { Button, Input } from 'antd';

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

function CountPill({
    label,
    value,
    className,
}: {
    label: string;
    value: number;
    className: string;
}) {
    return (
        <div className={`flex items-center gap-2 rounded-xl px-3 py-1 ${className}`}>
            <span className="text-sm font-bold">{label}</span>
            <span className="text-2xl font-black tabular-nums leading-none">{value}</span>
        </div>
    );
}

export default function KdsTopBar({
    stationLabel,
    counts,
    query,
    onQueryChange,
    onSearch,
    pageIndex,
    pageCount,
    allDayOpen,
    onToggleAllDay,
    recallCount,
    recallOpen,
    onToggleRecall,
    connection,
    clock,
    onOpenSettings,
}: KdsTopBarProps) {
    const connected = connection === 'connected';
    const connecting = connection === 'connecting' || connection === 'initialized';
    const dot = connected ? 'bg-emerald-400' : connecting ? 'bg-amber-400' : 'bg-red-500';

    return (
        <header className="flex shrink-0 flex-wrap items-center gap-2 border-b border-white/10 bg-zinc-900 px-3 py-2">
            <div className="min-w-0 pe-2">
                <div className="truncate text-2xl font-black leading-none">{stationLabel}</div>
                <div className="mt-1 text-sm font-bold text-zinc-400">
                    صفحة {pageCount === 0 ? 1 : pageIndex + 1} / {Math.max(pageCount, 1)}
                </div>
            </div>

            <CountPill label="جديد" value={counts.fresh} className="bg-emerald-500/20 text-emerald-300" />
            <CountPill label="تحذير" value={counts.warning} className="bg-amber-500/20 text-amber-300" />
            <CountPill
                label="متأخر"
                value={counts.late}
                className={counts.late > 0 ? 'animate-pulse bg-red-600 text-white' : 'bg-red-500/15 text-red-300'}
            />

            <Input
                size="large"
                allowClear
                inputMode="numeric"
                autoComplete="off"
                placeholder="رقم الطلب"
                prefix={<SearchOutlined />}
                value={query}
                onChange={(event) => onQueryChange(event.target.value)}
                onPressEnter={onSearch}
                style={{ width: 190, height: 48 }}
                aria-label="بحث برقم الطلب"
            />

            <div className="ms-auto flex flex-wrap items-center gap-2">
                <Button
                    size="large"
                    type={allDayOpen ? 'primary' : 'default'}
                    icon={<UnorderedListOutlined />}
                    onClick={onToggleAllDay}
                    style={{ height: 48 }}
                >
                    الإجمالي
                </Button>
                <Button
                    size="large"
                    type={recallOpen ? 'primary' : 'default'}
                    icon={<RollbackOutlined />}
                    onClick={onToggleRecall}
                    style={{ height: 48 }}
                >
                    استرجاع ({recallCount})
                </Button>
                <div className="flex items-center gap-2 px-1 font-bold" title={connectionLabel(connection)}>
                    <span className={`h-4 w-4 rounded-full ${dot} ${connected ? '' : 'animate-pulse'}`} />
                    <span className="text-sm">{connectionLabel(connection)}</span>
                </div>
                <div dir="ltr" className="min-w-[7.5rem] text-center text-2xl font-black tabular-nums">
                    {clock}
                </div>
                <Button
                    size="large"
                    icon={<SettingOutlined />}
                    onClick={onOpenSettings}
                    style={{ height: 48 }}
                    aria-label="إعدادات الشاشة"
                >
                    إعدادات
                </Button>
            </div>
        </header>
    );
}
