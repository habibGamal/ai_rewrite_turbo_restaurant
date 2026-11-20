import { kdsTokens } from '@/Components/Kitchen/kdsTokens';
import { SoundFilled } from '@ant-design/icons';

interface KdsStartOverlayProps {
    onStart: () => void;
}

export default function KdsStartOverlay({ onStart }: KdsStartOverlayProps) {
    return (
        <div
            className="fixed inset-0 z-[2000] flex items-center justify-center p-6 backdrop-blur-sm"
            style={{ background: 'rgba(9, 12, 18, 0.88)' }}
        >
            <button
                type="button"
                onClick={onStart}
                className="kds-press flex min-h-[48px] min-w-[300px] flex-col items-center justify-center gap-4 rounded-3xl border-2 px-12 py-10 text-white shadow-2xl"
                style={{
                    borderColor: kdsTokens.ack,
                    background: kdsTokens.card,
                    boxShadow: '0 24px 64px rgba(0,0,0,0.6)',
                }}
            >
                <span
                    className="flex h-16 w-16 items-center justify-center rounded-2xl text-3xl"
                    style={{ background: 'rgba(245, 158, 11, 0.16)', color: kdsTokens.ack }}
                >
                    <SoundFilled />
                </span>
                <span className="text-4xl font-black leading-none">اضغط للبدء</span>
                <span className="text-lg font-bold" style={{ color: kdsTokens.noteStrong }}>
                    لتفعيل الصوت وإبقاء الشاشة مضاءة
                </span>
            </button>
        </div>
    );
}
