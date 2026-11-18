import KdsBlock from '@/Components/Kitchen/KdsTicketBlocks';
import KdsTicketFragment from '@/Components/Kitchen/KdsTicketFragment';
import { kdsTokens } from '@/Components/Kitchen/kdsTokens';
import { FRAGMENT_GAP, type PackedPage } from '@/Components/Kitchen/packTickets';
import { measureKey, ticketBlockKeys } from '@/Components/Kitchen/ticketModel';
import type { KdsTicket } from '@/types/kitchen';
import { LeftOutlined, RightOutlined } from '@ant-design/icons';
import { memo, useEffect, useLayoutEffect, useRef, type TouchEvent } from 'react';

export interface PageEdgeStats {
    count: number;
    hasLate: boolean;
}

export type BoardEmptyKind = 'shift' | 'station' | 'filter' | 'none' | null;

interface KdsBoardProps {
    tickets: KdsTicket[];
    pages: PackedPage[];
    pageIndex: number;
    columnCount: number;
    columnWidth: number;
    clock: string;
    onMeasure: (heights: Map<string, number>) => void;
    onViewport: (size: { width: number; height: number }) => void;
    onPageIndex: (index: number) => void;
    previous: PageEdgeStats;
    next: PageEdgeStats;
    keyboardEnabled: boolean;
    empty: BoardEmptyKind;
    onResetStation?: () => void;
    onResetFilter?: () => void;
}

const KdsBoard = memo(function KdsBoard({
    tickets,
    pages,
    pageIndex,
    columnWidth,
    clock,
    onMeasure,
    onViewport,
    onPageIndex,
    previous,
    next,
    keyboardEnabled,
    empty,
    onResetStation,
    onResetFilter,
}: KdsBoardProps) {
    const measureRef = useRef<HTMLDivElement>(null);
    const rowRef = useRef<HTMLDivElement>(null);
    const touch = useRef<{ x: number; y: number; ignore: boolean } | null>(null);
    const page = pages[pageIndex];
    const pageCount = pages.length;

    useLayoutEffect(() => {
        const root = measureRef.current;
        if (!root || columnWidth <= 0) {
            return;
        }
        const nextHeights = new Map<string, number>();
        root.querySelectorAll<HTMLElement>('[data-measure-key]').forEach((element) => {
            const key = element.dataset.measureKey;
            if (key) {
                nextHeights.set(key, element.offsetHeight);
            }
        });
        onMeasure(nextHeights);
    });

    useLayoutEffect(() => {
        const row = rowRef.current;
        if (!row) {
            return;
        }
        const report = (): void => {
            onViewport({ width: row.clientWidth, height: row.clientHeight });
        };
        report();
        const observer = new ResizeObserver(report);
        observer.observe(row);
        return () => observer.disconnect();
    }, [onViewport, empty]);

    useEffect(() => {
        if (!keyboardEnabled) {
            return;
        }
        const onKey = (event: KeyboardEvent): void => {
            const target = event.target;
            if (target instanceof HTMLElement) {
                const tag = target.tagName;
                if (tag === 'INPUT' || tag === 'TEXTAREA' || target.isContentEditable) {
                    return;
                }
                if (target.closest('.ant-drawer, .ant-modal, .ant-slider, .ant-dropdown')) {
                    return;
                }
            }
            if (event.key === 'ArrowLeft' || event.key === 'PageDown') {
                event.preventDefault();
                onPageIndex(Math.min(pageCount - 1, pageIndex + 1));
            } else if (event.key === 'ArrowRight' || event.key === 'PageUp') {
                event.preventDefault();
                onPageIndex(Math.max(0, pageIndex - 1));
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [keyboardEnabled, onPageIndex, pageCount, pageIndex]);

    const goNext = (): void => onPageIndex(Math.min(pageCount - 1, pageIndex + 1));
    const goPrev = (): void => onPageIndex(Math.max(0, pageIndex - 1));

    const onTouchStart = (event: TouchEvent<HTMLDivElement>): void => {
        const target = event.target;
        const ignore = target instanceof Element && Boolean(target.closest('button, a, input, textarea'));
        const point = event.touches[0];
        touch.current = { x: point.clientX, y: point.clientY, ignore };
    };

    const onTouchEnd = (event: TouchEvent<HTMLDivElement>): void => {
        const start = touch.current;
        touch.current = null;
        if (!start || start.ignore) {
            return;
        }
        const point = event.changedTouches[0];
        const dx = point.clientX - start.x;
        const dy = point.clientY - start.y;
        if (Math.abs(dx) < 56 || Math.abs(dx) < Math.abs(dy) * 1.2) {
            return;
        }
        if (dx < 0) {
            goNext();
        } else {
            goPrev();
        }
    };

    return (
        <div className="relative min-h-0 flex-1 overflow-hidden p-2.5" onTouchStart={onTouchStart} onTouchEnd={onTouchEnd}>
            {empty ? (
                <EmptyState
                    kind={empty}
                    clock={clock}
                    onResetStation={onResetStation}
                    onResetFilter={onResetFilter}
                />
            ) : (
                <div ref={rowRef} className="flex h-full overflow-hidden" style={{ gap: FRAGMENT_GAP }}>
                    {(page?.columns ?? []).map((column, index) => (
                        <div
                            key={index}
                            className="flex h-full min-w-0 flex-col overflow-hidden"
                            style={{ width: columnWidth, gap: FRAGMENT_GAP, flexShrink: 0 }}
                        >
                            {column.fragments.map((fragment) => {
                                const ticket = tickets.find((entry) => entry.id === fragment.ticketId);
                                if (!ticket) {
                                    return null;
                                }
                                return (
                                    <KdsTicketFragment
                                        key={`${fragment.ticketId}-${fragment.blockKeys[0] ?? 'empty'}`}
                                        ticket={ticket}
                                        fragment={fragment}
                                    />
                                );
                            })}
                        </div>
                    ))}
                </div>
            )}

            {pageCount > 1 && !empty && (
                <>
                    <PageButton side="previous" stats={previous} disabled={pageIndex <= 0} onClick={goPrev} />
                    <PageButton side="next" stats={next} disabled={pageIndex >= pageCount - 1} onClick={goNext} />
                </>
            )}

            <div
                ref={measureRef}
                aria-hidden
                className="pointer-events-none"
                style={{ position: 'absolute', visibility: 'hidden', top: 0, right: 0, width: columnWidth }}
            >
                {columnWidth > 0 &&
                    tickets.map((ticket) => (
                        <div key={ticket.id} style={{ width: columnWidth }}>
                            {ticketBlockKeys(ticket).map((blockKey) => (
                                <KdsBlock
                                    key={blockKey}
                                    ticket={ticket}
                                    blockKey={blockKey}
                                    measureKey={measureKey(ticket.id, blockKey)}
                                />
                            ))}
                        </div>
                    ))}
            </div>
        </div>
    );
});

export default KdsBoard;

function PageButton({
    side,
    stats,
    disabled,
    onClick,
}: {
    side: 'previous' | 'next';
    stats: PageEdgeStats;
    disabled: boolean;
    onClick: () => void;
}) {
    const label = side === 'previous' ? 'السابق' : 'التالي';
    return (
        <button
            type="button"
            disabled={disabled}
            onClick={onClick}
            aria-label={`${label}، ${stats.count} طلبات`}
            className={`kds-press absolute top-1/2 z-20 flex h-16 w-12 -translate-y-1/2 flex-col items-center justify-center rounded-2xl border-0 text-white backdrop-blur-sm ${
                disabled ? 'pointer-events-none opacity-25' : ''
            }`}
            style={{
                ...(side === 'previous' ? { right: 8 } : { left: 8 }),
                background: 'rgba(15, 20, 30, 0.90)',
                boxShadow: `0 4px 16px rgba(0,0,0,0.45), inset 0 0 0 1px ${kdsTokens.lineStrong}`,
            }}
        >
            {stats.hasLate && (
                <span
                    className="kds-late-timer absolute -top-1 h-3.5 w-3.5 rounded-full"
                    style={{ background: kdsTokens.danger, ...(side === 'previous' ? { left: -2 } : { right: -2 }) }}
                />
            )}
            {side === 'previous' ? <RightOutlined /> : <LeftOutlined />}
            {stats.count > 0 && (
                <span
                    className="text-sm font-black tabular-nums"
                    style={{ color: stats.hasLate ? kdsTokens.timerLate : kdsTokens.text }}
                >
                    {stats.count}
                </span>
            )}
        </button>
    );
}

function EmptyState({
    kind,
    clock,
    onResetStation,
    onResetFilter,
}: {
    kind: Exclude<BoardEmptyKind, null>;
    clock: string;
    onResetStation?: () => void;
    onResetFilter?: () => void;
}) {
    const title =
        kind === 'shift'
            ? 'لا يوجد وردية نشطة'
            : kind === 'station'
              ? 'لا توجد طلبات لهذه المحطة'
              : kind === 'filter'
                ? 'لا توجد طلبات مطابقة للتصفية'
                : 'لا توجد طلبات حالياً';

    const detail =
        kind === 'shift'
            ? 'يرجى بدء وردية جديدة من شاشة الكاشير'
            : kind === 'station'
              ? 'ستظهر طلبات هذه المحطة فور إرسالها أو يمكنك عرض كل المحطات'
              : kind === 'filter'
                ? 'جرّب تغيير التصفية لعرض باقي الطلبات النشطة'
                : 'ستظهر الطلبات الجديدة هنا تلقائياً فور وصولها';

    return (
        <div className="flex h-full w-full flex-col items-center justify-center px-6 text-center">
            <div
                className="mb-4 rounded-2xl px-6 py-3"
                style={{
                    background: kdsTokens.card,
                    boxShadow: `inset 0 0 0 1px ${kdsTokens.line}`,
                }}
            >
                <div dir="ltr" className="text-5xl font-black leading-none tabular-nums" style={{ color: kdsTokens.textSoft }}>
                    {clock}
                </div>
            </div>
            <div className="text-3xl font-black" style={{ color: kdsTokens.text }}>
                {title}
            </div>
            <div className="mt-2 max-w-md text-lg font-bold" style={{ color: kdsTokens.textDim }}>
                {detail}
            </div>
            {kind === 'station' && onResetStation && (
                <button
                    type="button"
                    onClick={onResetStation}
                    className="kds-press mt-6 h-12 rounded-xl border-0 px-6 text-base font-black"
                    style={{
                        background: kdsTokens.cardAlt,
                        color: kdsTokens.text,
                        boxShadow: `inset 0 0 0 1px ${kdsTokens.lineStrong}`,
                    }}
                >
                    عرض كل المحطات
                </button>
            )}
            {kind === 'filter' && onResetFilter && (
                <button
                    type="button"
                    onClick={onResetFilter}
                    className="kds-press mt-6 h-12 rounded-xl border-0 px-6 text-base font-black"
                    style={{
                        background: kdsTokens.cardAlt,
                        color: kdsTokens.text,
                        boxShadow: `inset 0 0 0 1px ${kdsTokens.lineStrong}`,
                    }}
                >
                    عرض كل الطلبات
                </button>
            )}
        </div>
    );
}
