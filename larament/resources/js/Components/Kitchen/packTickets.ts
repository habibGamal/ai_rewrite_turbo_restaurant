// Newspaper-column packing for the KDS board.
// Tickets flow down a column; a block that does not fit continues in the next
// column (RTL: the next column is to the left) with a "يتبع" marker. Pages are
// horizontal. Heights are measured by the caller — this function is pure.

export const CONTINUATION_MARKER_HEIGHT = 40;
export const FRAGMENT_GAP = 8;
/** Vertical border of a ticket fragment (2px top + 2px bottom). */
export const CARD_CHROME_HEIGHT = 4;

export interface BlockMeasure {
    key: string;
    height: number;
}

export interface TicketMeasure {
    id: number;
    blocks: BlockMeasure[];
}

export interface PackedFragment {
    ticketId: number;
    blockKeys: string[];
    /** Ticket already started in a previous column — show يتبع above. */
    continued: boolean;
    /** Ticket continues in a later column — the next fragment shows يتبع. */
    continues: boolean;
    /** Bottom يتبع fits in this column. */
    bottomMarker: boolean;
}

export interface PackedColumn {
    fragments: PackedFragment[];
}

export interface PackedPage {
    columns: PackedColumn[];
}

interface OpenFragment {
    ticketId: number;
    blockKeys: string[];
    continued: boolean;
    continues: boolean;
    bottomMarker: boolean;
}

export function emptyPackedPage(columnCount: number): PackedPage {
    return {
        columns: Array.from({ length: Math.max(1, columnCount) }, () => ({ fragments: [] })),
    };
}

function pageHasFragments(page: PackedPage): boolean {
    return page.columns.some((column) => column.fragments.length > 0);
}

export function packTickets(
    tickets: TicketMeasure[],
    columnHeight: number,
    columnCount: number,
): PackedPage[] {
    const columns = Math.max(1, Math.floor(columnCount));
    const height = Math.floor(columnHeight);

    if (height <= 0 || tickets.length === 0) {
        return [emptyPackedPage(columns)];
    }

    const pages: PackedPage[] = [];
    let page = emptyPackedPage(columns);
    let col = 0;
    let used = 0;
    let open: OpenFragment | null = null;

    const commit = (): void => {
        if (!open) {
            return;
        }
        page.columns[col].fragments.push(open);
        open = null;
    };

    const advance = (): void => {
        col += 1;
        used = 0;
        if (col < columns) {
            return;
        }
        pages.push(page);
        page = emptyPackedPage(columns);
        col = 0;
    };

    const spill = (): void => {
        if (open) {
            open.continues = true;
            if (height - used >= CONTINUATION_MARKER_HEIGHT) {
                used += CONTINUATION_MARKER_HEIGHT;
                open.bottomMarker = true;
            }
            commit();
        }
        advance();
    };

    const tryPlace = (ticketId: number, block: BlockMeasure, continued: boolean): 'placed' | 'retry-continued' | 'retry-fresh' => {
        const adding = open?.ticketId === ticketId;
        let overhead = 0;
        if (!adding) {
            if (page.columns[col].fragments.length > 0) {
                overhead += FRAGMENT_GAP;
            }
            overhead += CARD_CHROME_HEIGHT;
            if (continued) {
                overhead += CONTINUATION_MARKER_HEIGHT;
            }
        }

        const blockHeight = Math.max(0, block.height);
        if (used + overhead + blockHeight <= height) {
            if (!adding) {
                used += overhead;
                open = {
                    ticketId,
                    blockKeys: [],
                    continued,
                    continues: false,
                    bottomMarker: false,
                };
            }
            open?.blockKeys.push(block.key);
            used += blockHeight;
            return 'placed';
        }

        // Empty column and the block still does not fit: place it anyway so we
        // cannot loop. The column clips the overflow; later blocks continue.
        if (used === 0 && !adding) {
            used += overhead;
            open = {
                ticketId,
                blockKeys: [block.key],
                continued,
                continues: false,
                bottomMarker: false,
            };
            used += blockHeight;
            return 'placed';
        }

        const midTicket = adding;
        spill();
        return midTicket ? 'retry-continued' : 'retry-fresh';
    };

    for (const ticket of tickets) {
        const current = open as OpenFragment | null;
        if (current !== null && current.ticketId !== ticket.id) {
            commit();
        }

        let continued = false;
        for (const block of ticket.blocks) {
            let guard = 0;
            let placed = false;
            while (!placed && guard < 80) {
                guard += 1;
                const result = tryPlace(ticket.id, block, continued);
                if (result === 'placed') {
                    placed = true;
                } else if (result === 'retry-continued') {
                    continued = true;
                } else {
                    continued = false;
                }
            }
        }
    }

    if (open) {
        commit();
    }
    if (pageHasFragments(page) || pages.length === 0) {
        pages.push(page);
    }

    return pages;
}

export function pageOfTicket(pages: PackedPage[], ticketId: number): number {
    return pages.findIndex((page) =>
        page.columns.some((column) =>
            column.fragments.some((fragment) => fragment.ticketId === ticketId),
        ),
    );
}

function idsOnPage(page: PackedPage | undefined): Set<number> {
    const ids = new Set<number>();
    if (!page) {
        return ids;
    }
    for (const column of page.columns) {
        for (const fragment of column.fragments) {
            ids.add(fragment.ticketId);
        }
    }
    return ids;
}

/** Ticket ids that appear on pages in `direction` and are not already on the current page. */
export function otherPageTicketIds(
    pages: PackedPage[],
    pageIndex: number,
    direction: 'previous' | 'next',
): Set<number> {
    const current = idsOnPage(pages[pageIndex]);
    const ids = new Set<number>();
    const start = direction === 'previous' ? 0 : pageIndex + 1;
    const end = direction === 'previous' ? pageIndex - 1 : pages.length - 1;
    for (let index = start; index <= end; index += 1) {
        for (const id of idsOnPage(pages[index])) {
            if (!current.has(id)) {
                ids.add(id);
            }
        }
    }
    return ids;
}
