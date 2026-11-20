export type KdsColumnSetting = 'auto' | 3 | 4 | 5 | 6;

export interface KdsScreenSettings {
    stationId: number | null;
    columns: KdsColumnSetting;
    fontScale: number;
    muted: boolean;
}

export const KDS_SCREEN_STORAGE_KEY = 'kds.screen';

export const DEFAULT_SCREEN_SETTINGS: KdsScreenSettings = {
    stationId: null,
    columns: 'auto',
    fontScale: 100,
    muted: false,
};

const COLUMN_VALUES: readonly KdsColumnSetting[] = ['auto', 3, 4, 5, 6];

function isColumnSetting(value: unknown): value is KdsColumnSetting {
    return COLUMN_VALUES.some((entry) => entry === value);
}

function sanitize(raw: unknown): KdsScreenSettings {
    if (!raw || typeof raw !== 'object') {
        return DEFAULT_SCREEN_SETTINGS;
    }
    const record = raw as Record<string, unknown>;
    const stationId =
        typeof record.stationId === 'number' && Number.isFinite(record.stationId)
            ? record.stationId
            : null;
    const fontScale =
        typeof record.fontScale === 'number' && Number.isFinite(record.fontScale)
            ? Math.min(150, Math.max(90, Math.round(record.fontScale)))
            : DEFAULT_SCREEN_SETTINGS.fontScale;

    return {
        stationId,
        columns: isColumnSetting(record.columns) ? record.columns : 'auto',
        fontScale,
        muted: record.muted === true,
    };
}

export function readScreenSettings(): KdsScreenSettings {
    try {
        const raw = localStorage.getItem(KDS_SCREEN_STORAGE_KEY);
        if (!raw) {
            return DEFAULT_SCREEN_SETTINGS;
        }
        return sanitize(JSON.parse(raw));
    } catch {
        return DEFAULT_SCREEN_SETTINGS;
    }
}

export function writeScreenSettings(settings: KdsScreenSettings): void {
    try {
        localStorage.setItem(KDS_SCREEN_STORAGE_KEY, JSON.stringify(settings));
    } catch {
        // Private mode or a full disk should not break the screen.
    }
}

/**
 * Target card width ~320px (within 300–340). 1918px → 6 columns (~308px).
 * Always 3–6. A forced screen setting overrides this.
 */
export function autoColumnCount(availableWidth: number): 3 | 4 | 5 | 6 {
    const gap = 10;
    const target = 320;
    const usable = Math.max(0, availableWidth);
    const count = Math.round((usable + gap) / (target + gap));
    if (count >= 6) {
        return 6;
    }
    if (count >= 5) {
        return 5;
    }
    if (count >= 4) {
        return 4;
    }
    return 3;
}

export function resolveStationId(saved: number | null, stationIds: number[]): number | null {
    if (saved == null) {
        return null;
    }
    return stationIds.includes(saved) ? saved : null;
}
