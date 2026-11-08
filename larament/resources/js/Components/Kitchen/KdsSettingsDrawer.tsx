import type { KdsColumnSetting } from '@/Components/Kitchen/screenSettings';
import type { KdsSound, KdsStation } from '@/types/kitchen';
import { Drawer, Slider, Switch } from 'antd';

const COLUMN_OPTIONS: { value: KdsColumnSetting; label: string }[] = [
    { value: 'auto', label: 'تلقائي' },
    { value: 3, label: '3' },
    { value: 4, label: '4' },
    { value: 5, label: '5' },
    { value: 6, label: '6' },
];

const SOUND_TESTS: { sound: KdsSound; label: string }[] = [
    { sound: 'chime', label: 'نغمة' },
    { sound: 'bell', label: 'جرس' },
    { sound: 'beep', label: 'صفارة' },
    { sound: 'alert', label: 'تنبيه' },
];

interface KdsSettingsDrawerProps {
    open: boolean;
    onClose: () => void;
    stations: KdsStation[];
    stationId: number | null;
    columns: KdsColumnSetting;
    fontScale: number;
    muted: boolean;
    onStationId: (stationId: number | null) => void;
    onColumns: (columns: KdsColumnSetting) => void;
    onFontScale: (fontScale: number) => void;
    onMuted: (muted: boolean) => void;
    onTestSound: (sound: KdsSound) => void;
}

export default function KdsSettingsDrawer({
    open,
    onClose,
    stations,
    stationId,
    columns,
    fontScale,
    muted,
    onStationId,
    onColumns,
    onFontScale,
    onMuted,
    onTestSound,
}: KdsSettingsDrawerProps) {
    return (
        <Drawer title="إعدادات الشاشة" placement="left" open={open} onClose={onClose} width={420} destroyOnClose={false}>
            <div className="flex flex-col gap-6 pb-8">
                <section>
                    <h3 className="mb-2 text-lg font-black">المحطة</h3>
                    <div className="flex flex-col gap-2">
                        <ChoiceButton active={stationId == null} onClick={() => onStationId(null)}>
                            كل المحطات
                        </ChoiceButton>
                        {stations.map((station) => (
                            <ChoiceButton
                                key={station.id}
                                active={stationId === station.id}
                                onClick={() => onStationId(station.id)}
                            >
                                {station.name}
                            </ChoiceButton>
                        ))}
                    </div>
                </section>

                <section>
                    <h3 className="mb-2 text-lg font-black">عدد الأعمدة</h3>
                    <p className="mb-2 text-sm text-zinc-400">تلقائي: 3 حتى 1400، ثم 4 / 5 / 6 حسب عرض الشاشة.</p>
                    <div className="grid grid-cols-5 gap-2">
                        {COLUMN_OPTIONS.map((option) => (
                            <ChoiceButton
                                key={String(option.value)}
                                active={columns === option.value}
                                onClick={() => onColumns(option.value)}
                            >
                                {option.label}
                            </ChoiceButton>
                        ))}
                    </div>
                </section>

                <section>
                    <div className="mb-2 flex items-center justify-between">
                        <h3 className="text-lg font-black">حجم الخط</h3>
                        <span className="text-xl font-black tabular-nums">{fontScale}%</span>
                    </div>
                    <Slider
                        min={90}
                        max={150}
                        step={5}
                        value={fontScale}
                        onChange={(value) => onFontScale(Array.isArray(value) ? value[0] : value)}
                        tooltip={{ formatter: (value) => `${value}%` }}
                    />
                    <div className="mt-2 font-black" style={{ fontSize: `${Math.round(28 * (fontScale / 100))}px` }}>
                        معاينة الطلب
                    </div>
                </section>

                <section className="flex items-center justify-between gap-3">
                    <div>
                        <h3 className="text-lg font-black">كتم الصوت</h3>
                        <p className="text-sm text-zinc-400">لهذه الشاشة فقط. تجربة الصوت تعمل رغم الكتم.</p>
                    </div>
                    <Switch checked={muted} onChange={onMuted} aria-label="كتم الصوت" />
                </section>

                <section>
                    <h3 className="mb-2 text-lg font-black">تجربة الصوت</h3>
                    <div className="grid grid-cols-2 gap-2">
                        {SOUND_TESTS.map((entry) => (
                            <button
                                key={entry.sound}
                                type="button"
                                className="min-h-12 rounded-xl bg-zinc-700 px-3 font-black text-white"
                                onClick={() => onTestSound(entry.sound)}
                            >
                                تجربة {entry.label}
                            </button>
                        ))}
                    </div>
                </section>
            </div>
        </Drawer>
    );
}

function ChoiceButton({
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
            className={`min-h-12 rounded-xl px-3 text-lg font-black ${
                active ? 'bg-violet-600 text-white' : 'bg-zinc-800 text-zinc-100'
            }`}
        >
            {children}
        </button>
    );
}
