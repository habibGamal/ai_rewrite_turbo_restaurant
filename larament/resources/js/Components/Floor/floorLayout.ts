import type React from 'react';
import type {
    FloorLayout,
    FloorRect,
    FloorSection,
    FloorTable,
    TableLabelConfig,
    TableShape,
} from '@/types';

export interface Cell {
    col: number;
    row: number;
}

export type ToolMode = 'select' | 'add_table' | 'draw_section';

export type SelectionTarget =
    | { kind: 'table'; index: number }
    | { kind: 'section'; index: number };

export interface LayoutProblem {
    id: string;
    message: string;
    target?: SelectionTarget;
}

export interface GhostPreview extends FloorRect {
    valid: boolean;
    kind: 'table' | 'section';
    shape?: TableShape;
    label?: string;
}

export const EMPTY_FLOOR_LAYOUT: FloorLayout = {
    version: 1,
    enabled: false,
    columns: 8,
    rows: 6,
    tables: [],
    sections: [],
};

export const DEFAULT_TABLE_LABEL: TableLabelConfig = {
    prefix: 'طاولة',
    showCapacity: true,
    showOrderNumber: true,
    showTimer: true,
};

export function resolveTableLabel(
    tableLabel?: Partial<TableLabelConfig> | null,
): TableLabelConfig {
    if (!tableLabel) {
        return { ...DEFAULT_TABLE_LABEL };
    }
    return {
        prefix:
            tableLabel.prefix === undefined
                ? DEFAULT_TABLE_LABEL.prefix
                : (tableLabel.prefix ?? ''),
        showCapacity: tableLabel.showCapacity ?? DEFAULT_TABLE_LABEL.showCapacity,
        showOrderNumber:
            tableLabel.showOrderNumber ?? DEFAULT_TABLE_LABEL.showOrderNumber,
        showTimer: tableLabel.showTimer ?? DEFAULT_TABLE_LABEL.showTimer,
    };
}

export function formatTableLabel(
    table: { number: string; capacity?: number },
    tableLabel?: Partial<TableLabelConfig> | null,
): string {
    const config = resolveTableLabel(tableLabel);
    const trimmedPrefix = config.prefix.trim();
    const baseName =
        trimmedPrefix === ''
            ? table.number
            : /[-_/#]$/.test(trimmedPrefix)
              ? `${trimmedPrefix}${table.number}`
              : `${trimmedPrefix} ${table.number}`;

    if (
        config.showCapacity &&
        typeof table.capacity === 'number' &&
        Number.isFinite(table.capacity) &&
        table.capacity > 0
    ) {
        return `${baseName} · ${table.capacity} أفراد`;
    }

    return baseName;
}

export const SECTION_COLOR_PALETTE: string[] = [
    '#e8f5e9', '#e3f2fd', '#fff3e0', '#f3e5f5',
    '#fce4ec', '#e0f7fa', '#fffde7', '#efebe9',
];

export function normalizeLayout(layout: FloorLayout | null): FloorLayout {
    if (!layout) {
        return { ...EMPTY_FLOOR_LAYOUT, tables: [], sections: [] };
    }
    return {
        ...layout,
        version: layout.version ?? 1,
        enabled: Boolean(layout.enabled),
        columns: layout.columns ?? 8,
        rows: layout.rows ?? 6,
        tables: Array.isArray(layout.tables)
            ? layout.tables.map((t) => ({ ...t, shape: t.shape === 'round' ? 'round' : 'square' }))
            : [],
        sections: Array.isArray(layout.sections) ? layout.sections.map((s) => ({ ...s })) : [],
        ...(layout.tableLabel ? { tableLabel: resolveTableLabel(layout.tableLabel) } : {}),
    };
}

export function gridPositionStyle(rect: FloorRect): React.CSSProperties {
    return {
        gridColumn: `${rect.col + 1} / span ${rect.colSpan}`,
        gridRow: `${rect.row + 1} / span ${rect.rowSpan}`,
    };
}

export function rectsOverlap(a: FloorRect, b: FloorRect): boolean {
    return (
        a.col < b.col + b.colSpan &&
        a.col + a.colSpan > b.col &&
        a.row < b.row + b.rowSpan &&
        a.row + a.rowSpan > b.row
    );
}

export function cellInRect(cell: Cell, rect: FloorRect): boolean {
    return (
        cell.col >= rect.col &&
        cell.col < rect.col + rect.colSpan &&
        cell.row >= rect.row &&
        cell.row < rect.row + rect.rowSpan
    );
}

export function isRectInBounds(rect: FloorRect, columns: number, rows: number): boolean {
    if (rect.col < 0 || rect.row < 0 || rect.colSpan < 1 || rect.rowSpan < 1) {
        return false;
    }
    return rect.col + rect.colSpan <= columns && rect.row + rect.rowSpan <= rows;
}

export function clampRectToGrid(rect: FloorRect, columns: number, rows: number): FloorRect {
    const col = Math.max(0, Math.min(columns - 1, rect.col));
    const row = Math.max(0, Math.min(rows - 1, rect.row));
    return {
        col,
        row,
        colSpan: Math.max(1, Math.min(Math.max(1, columns - col), rect.colSpan)),
        rowSpan: Math.max(1, Math.min(Math.max(1, rows - row), rect.rowSpan)),
    };
}

export function rectFromCells(start: Cell, end: Cell): FloorRect {
    const col = Math.min(start.col, end.col);
    const row = Math.min(start.row, end.row);
    return {
        col,
        row,
        colSpan: Math.max(start.col, end.col) - col + 1,
        rowSpan: Math.max(start.row, end.row) - row + 1,
    };
}

function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

export function nextTableNumber(tables: FloorTable[], prefix: string = ''): string {
    const normalizedPrefix = prefix.trim();
    const usedNumbers = new Set(tables.map((table) => table.number.trim()));

    if (normalizedPrefix === '') {
        let highestNumeric = 0;
        for (const table of tables) {
            const trimmed = table.number.trim();
            if (/^\d+$/.test(trimmed)) {
                highestNumeric = Math.max(highestNumeric, Number.parseInt(trimmed, 10));
            }
        }
        let candidate = highestNumeric + 1;
        while (usedNumbers.has(String(candidate))) {
            candidate += 1;
        }
        return String(candidate);
    }

    const pattern = new RegExp(`^${escapeRegExp(normalizedPrefix)}(\\d+)$`);
    let highestWithPrefix = 0;
    for (const table of tables) {
        const match = table.number.trim().match(pattern);
        if (match) {
            highestWithPrefix = Math.max(highestWithPrefix, Number.parseInt(match[1], 10));
        }
    }

    let suffix = highestWithPrefix + 1;
    while (usedNumbers.has(`${normalizedPrefix}${suffix}`)) {
        suffix += 1;
    }
    return `${normalizedPrefix}${suffix}`;
}

export function nextSectionName(sections: FloorSection[]): string {
    const usedNames = new Set(sections.map((section) => section.name.trim()));
    let highestIndex = sections.length;

    for (const section of sections) {
        const match = section.name.trim().match(/^قسم\s+(\d+)$/);
        if (match) {
            highestIndex = Math.max(highestIndex, Number.parseInt(match[1], 10));
        }
    }

    let candidateIndex = !usedNames.has(`قسم ${sections.length + 1}`)
        ? sections.length + 1
        : Math.max(1, highestIndex + 1);

    while (usedNames.has(`قسم ${candidateIndex}`)) {
        candidateIndex += 1;
    }
    return `قسم ${candidateIndex}`;
}

export function nextSectionColor(sections: FloorSection[]): string {
    return SECTION_COLOR_PALETTE[sections.length % SECTION_COLOR_PALETTE.length];
}

export function findTableAtCell(tables: FloorTable[], cell: Cell): number | null {
    for (let index = tables.length - 1; index >= 0; index -= 1) {
        if (cellInRect(cell, tables[index])) {
            return index;
        }
    }
    return null;
}

export function findSectionAtCell(sections: FloorSection[], cell: Cell): number | null {
    for (let index = sections.length - 1; index >= 0; index -= 1) {
        if (cellInRect(cell, sections[index])) {
            return index;
        }
    }
    return null;
}

export function isTablePlacementValid(
    layout: FloorLayout,
    rect: FloorRect,
    ignoreTableIndex?: number,
): boolean {
    if (!isRectInBounds(rect, layout.columns, layout.rows)) {
        return false;
    }
    return !layout.tables.some(
        (table, index) => index !== ignoreTableIndex && rectsOverlap(rect, table),
    );
}

export function isSectionPlacementValid(
    layout: FloorLayout,
    rect: FloorRect,
    ignoreSectionIndex?: number,
): boolean {
    if (!isRectInBounds(rect, layout.columns, layout.rows)) {
        return false;
    }
    return !layout.sections.some(
        (sec, index) => index !== ignoreSectionIndex && rectsOverlap(rect, sec),
    );
}

export function findZoneForCell(sections: FloorSection[], cell: Cell): string {
    for (let index = sections.length - 1; index >= 0; index -= 1) {
        if (cellInRect(cell, sections[index])) {
            return sections[index].name;
        }
    }
    return '';
}

export function assignTableZones(layout: FloorLayout): FloorLayout {
    return {
        ...layout,
        tables: layout.tables.map((table) => ({
            ...table,
            zone: findZoneForCell(layout.sections, { col: table.col, row: table.row }),
        })),
    };
}

export function checkGridResize(
    layout: FloorLayout,
    newColumns: number,
    newRows: number,
): string[] {
    const stranded: string[] = [];
    layout.tables.forEach((table, index) => {
        if (!isRectInBounds(table, newColumns, newRows)) {
            stranded.push(`الطاولة ${table.number.trim() || index + 1} خارج الشبكة`);
        }
    });
    layout.sections.forEach((section, index) => {
        if (!isRectInBounds(section, newColumns, newRows)) {
            stranded.push(`القسم "${section.name.trim() || index + 1}" خارج الشبكة`);
        }
    });
    return stranded;
}

export function validateFloorLayout(layout: FloorLayout): LayoutProblem[] {
    const problems: LayoutProblem[] = [];

    if (!Number.isInteger(layout.columns) || layout.columns < 1 || layout.columns > 100) {
        problems.push({ id: 'grid-columns', message: 'عدد الأعمدة يجب أن يكون بين 1 و 100' });
    }
    if (!Number.isInteger(layout.rows) || layout.rows < 1 || layout.rows > 100) {
        problems.push({ id: 'grid-rows', message: 'عدد الصفوف يجب أن يكون بين 1 و 100' });
    }

    const seenNumbers = new Map<string, number>();

    layout.tables.forEach((table, index) => {
        const trimmed = (table.number ?? '').trim();
        const label = trimmed || `#${index + 1}`;
        const target: SelectionTarget = { kind: 'table', index };

        if (trimmed === '') {
            problems.push({ id: `table-${index}-empty`, message: `الطاولة (${label}): رقم الطاولة مطلوب`, target });
        } else {
            if (trimmed.length > 50) {
                problems.push({
                    id: `table-${index}-max`,
                    message: `الطاولة ${trimmed}: رقم الطاولة يتجاوز 50 حرفاً`,
                    target,
                });
            }
            if (seenNumbers.has(trimmed)) {
                problems.push({ id: `table-${index}-duplicate`, message: `رقم الطاولة "${trimmed}" مكرر`, target });
            } else {
                seenNumbers.set(trimmed, index);
            }
        }

        if (!Number.isInteger(table.capacity) || table.capacity < 1) {
            problems.push({
                id: `table-${index}-capacity`,
                message: `الطاولة ${label}: السعة يجب أن تكون فرداً واحداً على الأقل`,
                target,
            });
        }

        if (!isRectInBounds(table, layout.columns, layout.rows)) {
            problems.push({ id: `table-${index}-bounds`, message: `الطاولة ${label} خارج حدود الشبكة`, target });
        }

        for (let otherIdx = index + 1; otherIdx < layout.tables.length; otherIdx += 1) {
            const other = layout.tables[otherIdx];
            if (rectsOverlap(table, other)) {
                const otherLabel = (other.number ?? '').trim() || `#${otherIdx + 1}`;
                problems.push({
                    id: `table-${index}-overlap-${otherIdx}`,
                    message: `الطاولة ${label} تتداخل مع الطاولة ${otherLabel}`,
                    target,
                });
            }
        }
    });

    layout.sections.forEach((section, index) => {
        const trimmed = (section.name ?? '').trim();
        const label = trimmed || `#${index + 1}`;
        const target: SelectionTarget = { kind: 'section', index };

        if (trimmed === '') {
            problems.push({ id: `section-${index}-empty`, message: `القسم (${label}): اسم القسم مطلوب`, target });
        }

        if (!isRectInBounds(section, layout.columns, layout.rows)) {
            problems.push({ id: `section-${index}-bounds`, message: `القسم "${label}" خارج حدود الشبكة`, target });
        }

        for (let otherIdx = index + 1; otherIdx < layout.sections.length; otherIdx += 1) {
            const other = layout.sections[otherIdx];
            if (rectsOverlap(section, other)) {
                const otherLabel = (other.name ?? '').trim() || `#${otherIdx + 1}`;
                problems.push({
                    id: `section-${index}-overlap-${otherIdx}`,
                    message: `القسم "${label}" يتداخل مع القسم "${otherLabel}"`,
                    target,
                });
            }
        }
    });

    return problems;
}

export function nudgeSelection(
    layout: FloorLayout,
    selection: SelectionTarget,
    dCol: number,
    dRow: number,
): FloorLayout | null {
    if (selection.kind === 'table') {
        const current = layout.tables[selection.index];
        if (!current) {
            return null;
        }
        const candidate: FloorTable = { ...current, col: current.col + dCol, row: current.row + dRow };
        if (!isTablePlacementValid(layout, candidate, selection.index)) {
            return null;
        }
        const tables = layout.tables.map((t, i) => (i === selection.index ? candidate : t));
        return assignTableZones({ ...layout, tables });
    }

    const currentSection = layout.sections[selection.index];
    if (!currentSection) {
        return null;
    }
    const candidateSection: FloorSection = {
        ...currentSection,
        col: currentSection.col + dCol,
        row: currentSection.row + dRow,
    };
    if (!isSectionPlacementValid(layout, candidateSection, selection.index)) {
        return null;
    }
    const sections = layout.sections.map((s, i) => (i === selection.index ? candidateSection : s));
    return assignTableZones({ ...layout, sections });
}

export function totalSeats(tables: FloorTable[]): number {
    return tables.reduce(
        (sum, table) => sum + (Number.isFinite(table.capacity) ? table.capacity : 0),
        0,
    );
}
