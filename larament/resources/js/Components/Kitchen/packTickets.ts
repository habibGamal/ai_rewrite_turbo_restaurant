// Masonry packing for the KDS board. Pure: heights come from the caller.
//
// Each ticket is placed whole into the column with the most remaining space
// (tie → lowest index). Column 0 is the rightmost column in the RTL board, so
// oldest-first order reads right-to-left. A ticket is split only when it is
// taller than an entire empty column — never just because the current column
// is partly full. Pages stay horizontal.

export const CONTINUATION_MARKER_HEIGHT = 22;
export const FRAGMENT_GAP = 12;
/** Top accent strip of a ticket fragment. Side borders are box-shadows and do not add height. */
export const CARD_CHROME_HEIGHT = 5;

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
    /** Ticket already started in a previous column — show تابع above. */
    continued: boolean;
    /** Ticket continues in a later column. */
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

export function emptyPackedPage(columnCount: number): PackedPage {
    return {
        columns: Array.from({ length: Math.max(1, columnCount) }, () => ({ fragments: [] })),
    };
}

function pageHasFragments(page: PackedPage): boolean {
    return page.columns.some((column) => column.fragments.length > 0);
}

function blockSum(ticket: TicketMeasure): number {
    return ticket.blocks.reduce((sum, block) => sum + Math.max(0, block.height), 0);
}

function wholeHeight(ticket: TicketMeasure): number {
    return CARD_CHROME_HEIGHT + blockSum(ticket);
}

export function packTickets(tickets: TicketMeasure[], columnHeight: number, columnCount: number): PackedPage[] {
    const columns = Math.max(1, Math.floor(columnCount));
    const height = Math.floor(columnHeight);

    if (height <= 0 || tickets.length === 0) {
        return [emptyPackedPage(columns)];
    }

    const pages: PackedPage[] = [];
    let page = emptyPackedPage(columns);
    let used = new Array<number>(columns).fill(0);

    const pushPage = (): void => {
        if (pageHasFragments(page)) {
            pages.push(page);
        }
        page = emptyPackedPage(columns);
        used = new Array<number>(columns).fill(0);
    };

    const neededIn = (ticket: TicketMeasure, col: number): number => {
        const gap = page.columns[col].fragments.length > 0 ? FRAGMENT_GAP : 0;
        return gap + wholeHeight(ticket);
    };

    const bestColumn = (ticket: TicketMeasure): number | null => {
        let best: number | null = null;
        let bestSpace = -1;
        for (let col = 0; col < columns; col += 1) {
            const space = height - used[col];
            if (neededIn(ticket, col) > space) {
                continue;
            }
            if (space > bestSpace) {
                best = col;
                bestSpace = space;
            }
        }
        return best;
    };

    const placeWhole = (ticket: TicketMeasure, col: number): void => {
        const gap = page.columns[col].fragments.length > 0 ? FRAGMENT_GAP : 0;
        used[col] += gap + wholeHeight(ticket);
        page.columns[col].fragments.push({
            ticketId: ticket.id,
            blockKeys: ticket.blocks.map((block) => block.key),
            continued: false,
            continues: false,
            bottomMarker: false,
        });
    };

    const firstEmptyColumn = (): number | null => {
        for (let col = 0; col < columns; col += 1) {
            if (used[col] === 0 && page.columns[col].fragments.length === 0) {
                return col;
            }
        }
        return null;
    };

    const splitAcross = (ticket: TicketMeasure): void => {
        let col = firstEmptyColumn();
        if (col == null) {
            pushPage();
            col = 0;
        }

        let index = 0;
        let continued = false;
        let guard = 0;

        while (index < ticket.blocks.length && guard < 200) {
            guard += 1;
            if (col >= columns) {
                pushPage();
                col = 0;
            }
            if (used[col] > 0) {
                col += 1;
                continue;
            }

            const fragment: PackedFragment = {
                ticketId: ticket.id,
                blockKeys: [],
                continued,
                continues: false,
                bottomMarker: false,
            };
            let cursor = CARD_CHROME_HEIGHT + (continued ? CONTINUATION_MARKER_HEIGHT : 0);

            while (index < ticket.blocks.length) {
                const block = ticket.blocks[index];
                const blockHeight = Math.max(0, block.height);
                const moreAfter = index < ticket.blocks.length - 1;
                const reserve = moreAfter ? CONTINUATION_MARKER_HEIGHT : 0;
                if (cursor + blockHeight + reserve <= height) {
                    fragment.blockKeys.push(block.key);
                    cursor += blockHeight;
                    index += 1;
                    continue;
                }
                if (fragment.blockKeys.length === 0) {
                    fragment.blockKeys.push(block.key);
                    cursor += blockHeight;
                    index += 1;
                }
                break;
            }

            const more = index < ticket.blocks.length;
            if (more) {
                fragment.continues = true;
                if (cursor + CONTINUATION_MARKER_HEIGHT <= height) {
                    cursor += CONTINUATION_MARKER_HEIGHT;
                    fragment.bottomMarker = true;
                }
            }

            if (fragment.blockKeys.length > 0) {
                page.columns[col].fragments.push(fragment);
                used[col] = cursor;
            }
            if (more) {
                col += 1;
                continued = true;
            }
        }
    };

    for (const ticket of tickets) {
        if (ticket.blocks.length === 0) {
            continue;
        }
        if (wholeHeight(ticket) <= height) {
            const col = bestColumn(ticket);
            if (col != null) {
                placeWhole(ticket, col);
            } else {
                pushPage();
                placeWhole(ticket, 0);
            }
            continue;
        }
        splitAcross(ticket);
    }

    if (pageHasFragments(page) || pages.length === 0) {
        pages.push(page);
    }
    return pages;
}

export function pageOfTicket(pages: PackedPage[], ticketId: number): number {
    return pages.findIndex((page) =>
        page.columns.some((column) => column.fragments.some((fragment) => fragment.ticketId === ticketId)),
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
