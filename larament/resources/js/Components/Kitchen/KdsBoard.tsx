import KdsBlock from '@/Components/Kitchen/KdsTicketBlocks';
import KdsTicketFragment from '@/Components/Kitchen/KdsTicketFragment';
import {
    FRAGMENT_GAP,
    CARD_CHROME_HEIGHT,
    type PackedPage,
} from '@/Components/Kitchen/packTickets';
import { measureKey, ticketBlockKeys } from '@/Components/Kitchen/ticketModel';
import type { KdsTicket } from '@/types/kitchen';
import { LeftOutlined, RightOutlined } from '@ant-design/icons';
import { useEffect, useLayoutEffect, useRef, type TouchEvent } from 'react';

export interface PageEdgeStats {
    count: number;
    hasLate: boolean;
}

interface KdsBoardProps {
    tickets: KdsTicket[];
    pages: PackedPage[];
    pageIndex: number;
    columnCount: number;
    columnWidth: number;
    onMeasure: (heights: Map<string, number>) => void;
    onViewport: (size: { width: number; height: number }) => void;
    onPageIndex: (index: number) => void;
    previous: PageEdgeStats;
    next: PageEdgeStats;
    keyboardEnabled: boolean;
    empty: 'shift' | 'station' | 'none' | null;
}

export default function KdsBoard({
    tickets,
    pages,
    pageIndex,
    columnCount,
    columnWidth,
    onMeasure,
    onViewport,
    onPageIndex,
    previous,
    next,
    keyboardEnabled,
    empty,
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
                if (target.closest('.ant-drawer, .ant-modal, .ant-slider')) {
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
        <div className="relative min-h-0 flex-1 p-2" onTouchStart={onTouchStart} onTouchEnd={onTouchEnd}>
            {empty ? (
                <EmptyState kind={empty} />
            ) : (
                <div ref={rowRef} className="flex h-full" style={{ gap: FRAGMENT_GAP }}>
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

            {pageCount > 1 && (
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
                        <div
                            key={ticket.id}
                            className="box-border"
                            style={{ width: columnWidth, borderWidth: CARD_CHROME_HEIGHT / 2, borderStyle: 'solid', borderColor: 'transparent' }}
                        >
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
}

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
            className={`absolute top-1/2 z-20 flex h-24 w-16 -translate-y-1/2 flex-col items-center justify-center rounded-2xl bg-zinc-800/95 text-white shadow-xl ${
                disabled ? 'pointer-events-none opacity-30' : ''
            }`}
            style={side === 'previous' ? { right: 8 } : { left: 8 }}
        >
            {stats.hasLate && <span className="absolute -top-1 h-5 w-5 rounded-full bg-red-500 ring-2 ring-black" style={side === 'previous' ? { left: -4 } : { right: -4 }} />}
            {side === 'previous' ? <RightOutlined className="text-xl" /> : <LeftOutlined className="text-xl" />}
            <span className="text-sm font-black">{label}</span>
            {stats.count > 0 && (
                <span className={`text-xl font-black tabular-nums ${stats.hasLate ? 'text-red-400' : ''}`}>{stats.count}</span>
            )}
        </button>
    );
}

function EmptyState({ kind }: { kind: 'shift' | 'station' | 'none' }) {
    const title =
        kind === 'shift' ? 'لا يوجد وردية نشطة' : kind === 'station' ? 'لا توجد طلبات لهذه المحطة' : 'لا توجد طلبات في المطبخ';
    const detail =
        kind === 'shift' ? 'يرجى بدء وردية جديدة من شاشة الكاشير' : kind === 'station' ? 'غيّر المحطة من الإعدادات لعرض الكل' : 'بانتظار طلبات جديدة';

    return (
        <div className="flex h-full items-center justify-center">
            <div className="text-center">
                <div className="text-4xl font-black">{title}</div>
                <div className="mt-3 text-xl font-bold text-zinc-400">{detail}</div>
            </div>
        </div>
    );
}
