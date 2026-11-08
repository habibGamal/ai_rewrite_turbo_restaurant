import KdsAllDayPanel from '@/Components/Kitchen/KdsAllDayPanel';
import KdsBoard, { type PageEdgeStats } from '@/Components/Kitchen/KdsBoard';
import KdsRecallDrawer from '@/Components/Kitchen/KdsRecallDrawer';
import KdsSettingsDrawer from '@/Components/Kitchen/KdsSettingsDrawer';
import KdsStartOverlay from '@/Components/Kitchen/KdsStartOverlay';
import KdsTopBar from '@/Components/Kitchen/KdsTopBar';
import { KdsProvider, type KdsContextValue } from '@/Components/Kitchen/kdsContext';
import { isConnectionLost, useKitchenChannel } from '@/Components/Kitchen/hooks/useKitchenChannel';
import { useKdsClock } from '@/Components/Kitchen/hooks/useKdsClock';
import { useKdsSounds } from '@/Components/Kitchen/hooks/useKdsSounds';
import { useOptimisticTickets } from '@/Components/Kitchen/hooks/useOptimisticTickets';
import { useScreenSettings } from '@/Components/Kitchen/hooks/useScreenSettings';
import { useViewportWidth } from '@/Components/Kitchen/hooks/useViewportWidth';
import { KDS_CSS, kdsCssVars, kdsTokens } from '@/Components/Kitchen/kdsTokens';
import { fadeLeavingTicket } from '@/Components/Kitchen/leaveFade';
import {
    FRAGMENT_GAP,
    emptyPackedPage,
    otherPageTicketIds,
    packTickets,
    pageOfTicket,
    type TicketMeasure,
} from '@/Components/Kitchen/packTickets';
import { autoColumnCount, resolveStationId } from '@/Components/Kitchen/screenSettings';
import {
    applyStationFilter,
    boardCounts,
    findTicket,
    formatClock,
    isBoardTicket,
    lineAction,
    measureKey,
    normalizeDigits,
    sortBoardTickets,
    sortRecallTickets,
    stationLabel,
    ticketBlockKeys,
    ticketUrgency,
} from '@/Components/Kitchen/ticketModel';
import type { KitchenPageProps, KdsItem, KdsSound, KdsTicket } from '@/types/kitchen';
import { App as AntApp } from 'antd';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

const HIGHLIGHT_MS = 4000;

function mapsEqual(left: Map<string, number>, right: Map<string, number>): boolean {
    if (left.size !== right.size) {
        return false;
    }
    for (const [key, value] of left) {
        if (right.get(key) !== value) {
            return false;
        }
    }
    return true;
}

function edgeStats(
    ids: Set<number>,
    tickets: KdsTicket[],
    nowMs: number,
    warningPercent: number,
): PageEdgeStats {
    let hasLate = false;
    for (const id of ids) {
        const ticket = tickets.find((entry) => entry.id === id);
        if (ticket && ticketUrgency(ticket, nowMs, warningPercent) === 'late') {
            hasLate = true;
            break;
        }
    }
    return { count: ids.size, hasLate };
}

export default function KdsScreen({
    tickets,
    stations,
    kdsSettings,
    serverNow,
    hasActiveShift,
}: KitchenPageProps) {
    const { modal, message } = AntApp.useApp();
    const screen = useScreenSettings();
    const viewportWidth = useViewportWidth();
    const { nowMs, now } = useKdsClock(serverNow);
    const stationId = resolveStationId(
        screen.settings.stationId,
        stations.map((station) => station.id),
    );
    const optimistic = useOptimisticTickets({
        tickets,
        now,
        stationId,
        onLeaving: fadeLeavingTicket,
    });

    const [boardSize, setBoardSize] = useState({ width: 0, height: 0 });
    const layoutWidth = boardSize.width > 0 ? boardSize.width : viewportWidth;
    const columnCount = screen.settings.columns === 'auto' ? autoColumnCount(layoutWidth) : screen.settings.columns;

    const filtered = useMemo(
        () => applyStationFilter(optimistic.tickets, stationId),
        [optimistic.tickets, stationId],
    );
    const board = useMemo(() => sortBoardTickets(filtered.filter(isBoardTicket)), [filtered]);
    const recall = useMemo(() => sortRecallTickets(filtered), [filtered]);
    const counts = useMemo(
        () => boardCounts(board, nowMs, kdsSettings.warning_percent),
        [board, nowMs, kdsSettings.warning_percent],
    );

    const sounds = useKdsSounds({
        settings: kdsSettings,
        muted: screen.settings.muted,
        tickets,
        attentionTickets: board,
    });
    const channel = useKitchenChannel({ stationId, onPayload: sounds.playPayload });

    const [query, setQuery] = useState('');
    const [pageIndex, setPageIndex] = useState(0);
    const [highlightId, setHighlightId] = useState<number | null>(null);
    const [pendingJumpId, setPendingJumpId] = useState<number | null>(null);
    const [allDayOpen, setAllDayOpen] = useState(false);
    const [recallOpen, setRecallOpen] = useState(false);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [heights, setHeights] = useState<Map<string, number>>(() => new Map());
    const highlightTimer = useRef<number | null>(null);
    const confirming = useRef(false);

    const columnWidth =
        columnCount > 0 ? Math.floor((boardSize.width - FRAGMENT_GAP * (columnCount - 1)) / columnCount) : 0;

    const measures = useMemo(() => buildMeasures(board, heights), [board, heights]);
    const pages = useMemo(() => {
        if (!measures || boardSize.height <= 0) {
            return [emptyPackedPage(columnCount)];
        }
        return packTickets(measures, boardSize.height, columnCount);
    }, [measures, boardSize.height, columnCount]);

    useEffect(() => {
        setPageIndex((current) => Math.min(current, Math.max(0, pages.length - 1)));
    }, [pages.length]);

    useEffect(() => {
        if (pendingJumpId == null) {
            return;
        }
        const index = pageOfTicket(pages, pendingJumpId);
        if (index >= 0) {
            setPageIndex(index);
            setPendingJumpId(null);
        }
    }, [pages, pendingJumpId]);

    useEffect(() => {
        return () => {
            if (highlightTimer.current != null) {
                window.clearTimeout(highlightTimer.current);
            }
        };
    }, []);

    const onMeasure = useCallback((next: Map<string, number>) => {
        setHeights((prev) => (mapsEqual(prev, next) ? prev : next));
    }, []);

    const onViewport = useCallback((size: { width: number; height: number }) => {
        setBoardSize((prev) => (prev.width === size.width && prev.height === size.height ? prev : size));
    }, []);

    const jumpTo = useCallback(
        (ticket: KdsTicket) => {
            setHighlightId(ticket.id);
            if (highlightTimer.current != null) {
                window.clearTimeout(highlightTimer.current);
            }
            highlightTimer.current = window.setTimeout(() => {
                setHighlightId((current) => (current === ticket.id ? null : current));
            }, HIGHLIGHT_MS);

            if (ticket.status === 'done') {
                setRecallOpen(true);
                return;
            }
            const index = pageOfTicket(pages, ticket.id);
            if (index >= 0) {
                setPageIndex(index);
            } else {
                setPendingJumpId(ticket.id);
            }
        },
        [pages],
    );

    const onQueryChange = (value: string): void => {
        setQuery(value);
        const normalized = normalizeDigits(value).trim();
        if (!normalized) {
            return;
        }
        const exact = [...board, ...recall].find((ticket) => String(ticket.order_number) === normalized);
        if (exact) {
            jumpTo(exact);
        }
    };

    const onSearch = (): void => {
        const match = findTicket([...board, ...recall], query);
        if (match) {
            jumpTo(match);
            return;
        }
        if (query.trim()) {
            message.warning('لا يوجد طلب بهذا الرقم');
        }
    };

    const confirmUndo = useCallback(
        (item: KdsItem, ticket: KdsTicket) => {
            if (confirming.current) {
                return;
            }
            confirming.current = true;
            modal.confirm({
                title: 'تراجع عن التجهيز؟',
                content: item.product_name,
                okText: 'تراجع',
                cancelText: 'إلغاء',
                okButtonProps: { danger: true },
                onOk: () => optimistic.applyItem(ticket, item, 'undo'),
                afterClose: () => {
                    confirming.current = false;
                },
            });
        },
        [modal, optimistic],
    );

    const tapItem = useCallback(
        (ticket: KdsTicket, item: KdsItem) => {
            const action = lineAction(ticket, item, kdsSettings, nowMs);
            if (!action) {
                return;
            }
            if (action === 'undo') {
                confirmUndo(item, ticket);
                return;
            }
            optimistic.applyItem(ticket, item, action);
        },
        [confirmUndo, kdsSettings, nowMs, optimistic],
    );

    const context = useMemo<KdsContextValue>(
        () => ({
            nowMs,
            scale: screen.settings.fontScale / 100,
            kdsSettings,
            stationId,
            stationNames: new Map(stations.map((station) => [station.id, station.name])),
            highlightedId: highlightId,
            tapItem,
            finishTicket: (ticket) => optimistic.finishTicket(ticket),
            acknowledge: (ticket) => optimistic.acknowledge(ticket),
            toggleRush: (ticket) => optimistic.toggleRush(ticket),
        }),
        [nowMs, screen.settings.fontScale, kdsSettings, stationId, stations, highlightId, tapItem, optimistic],
    );

    const empty = emptyKind(tickets, board, hasActiveShift, stationId);
    const previous = edgeStats(
        otherPageTicketIds(pages, pageIndex, 'previous'),
        board,
        nowMs,
        kdsSettings.warning_percent,
    );
    const next = edgeStats(otherPageTicketIds(pages, pageIndex, 'next'), board, nowMs, kdsSettings.warning_percent);

    return (
        <KdsProvider value={context}>
            <style>{KDS_CSS}</style>
            <div
                className="flex min-h-0 flex-1 touch-manipulation select-none flex-col"
                style={kdsCssVars}
            >
                {isConnectionLost(channel.connection) && (
                    <div
                        className="shrink-0 px-4 text-center text-base font-black text-white"
                        style={{ height: 36, lineHeight: '36px', background: kdsTokens.danger }}
                    >
                        انقطع الاتصال — يتم التحديث كل 30 ثانية
                    </div>
                )}
                <KdsTopBar
                    stationLabel={stationLabel(stationId, stations)}
                    counts={counts}
                    query={query}
                    onQueryChange={onQueryChange}
                    onSearch={onSearch}
                    pageIndex={pageIndex}
                    pageCount={pages.length}
                    allDayOpen={allDayOpen}
                    onToggleAllDay={() => setAllDayOpen((open) => !open)}
                    recallCount={recall.length}
                    recallOpen={recallOpen}
                    onToggleRecall={() => setRecallOpen((open) => !open)}
                    connection={channel.connection}
                    clock={formatClock(nowMs)}
                    onOpenSettings={() => setSettingsOpen(true)}
                />
                <div className="flex min-h-0 flex-1">
                    {allDayOpen && <KdsAllDayPanel tickets={board} onClose={() => setAllDayOpen(false)} />}
                    <KdsBoard
                        tickets={board}
                        pages={pages}
                        pageIndex={pageIndex}
                        columnCount={columnCount}
                        columnWidth={Math.max(0, columnWidth)}
                        clock={formatClock(nowMs)}
                        onMeasure={onMeasure}
                        onViewport={onViewport}
                        onPageIndex={setPageIndex}
                        previous={previous}
                        next={next}
                        keyboardEnabled={!settingsOpen && !recallOpen}
                        empty={empty}
                    />
                </div>
            </div>
            <KdsRecallDrawer
                open={recallOpen}
                onClose={() => setRecallOpen(false)}
                tickets={recall}
                nowMs={nowMs}
                recallMinutes={kdsSettings.recall_minutes}
                highlightedId={highlightId}
                onUndo={(item, ticket) => confirmUndo(item, ticket)}
            />
            <KdsSettingsDrawer
                open={settingsOpen}
                onClose={() => setSettingsOpen(false)}
                stations={stations}
                stationId={stationId}
                columns={screen.settings.columns}
                fontScale={screen.settings.fontScale}
                muted={screen.settings.muted}
                onStationId={screen.setStationId}
                onColumns={screen.setColumns}
                onFontScale={screen.setFontScale}
                onMuted={screen.setMuted}
                onTestSound={(sound: KdsSound) => sounds.play(sound, true)}
            />
            {sounds.needsUnlock && <KdsStartOverlay onStart={sounds.unlock} />}
        </KdsProvider>
    );
}

function emptyKind(
    tickets: KdsTicket[],
    board: KdsTicket[],
    hasActiveShift: boolean,
    stationId: number | null,
): 'shift' | 'station' | 'none' | null {
    if (board.length > 0) {
        return null;
    }
    if (tickets.length === 0 && !hasActiveShift) {
        return 'shift';
    }
    if (stationId != null && tickets.some(isBoardTicket)) {
        return 'station';
    }
    return 'none';
}

function buildMeasures(tickets: KdsTicket[], heights: Map<string, number>): TicketMeasure[] | null {
    const measures: TicketMeasure[] = [];
    for (const ticket of tickets) {
        const blocks: { key: string; height: number }[] = [];
        for (const key of ticketBlockKeys(ticket)) {
            const height = heights.get(measureKey(ticket.id, key));
            if (height == null) {
                return null;
            }
            blocks.push({ key, height });
        }
        measures.push({ id: ticket.id, blocks });
    }
    return measures;
}
