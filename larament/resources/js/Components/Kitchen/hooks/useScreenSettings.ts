import { useCallback, useState } from 'react';
import {
    readScreenSettings,
    writeScreenSettings,
    type KdsColumnSetting,
    type KdsScreenSettings,
} from '@/Components/Kitchen/screenSettings';

export function useScreenSettings(): {
    settings: KdsScreenSettings;
    setStationId: (stationId: number | null) => void;
    setColumns: (columns: KdsColumnSetting) => void;
    setFontScale: (fontScale: number) => void;
    setMuted: (muted: boolean) => void;
} {
    const [settings, setSettings] = useState<KdsScreenSettings>(() => readScreenSettings());

    const update = useCallback((patch: Partial<KdsScreenSettings>) => {
        setSettings((current) => {
            const next = { ...current, ...patch };
            writeScreenSettings(next);
            return next;
        });
    }, []);

    return {
        settings,
        setStationId: (stationId) => update({ stationId }),
        setColumns: (columns) => update({ columns }),
        setFontScale: (fontScale) => update({ fontScale: Math.min(150, Math.max(90, Math.round(fontScale))) }),
        setMuted: (muted) => update({ muted }),
    };
}
