import { kdsTokens } from '@/Components/Kitchen/kdsTokens';

interface KdsStartOverlayProps {
    onStart: () => void;
}

export default function KdsStartOverlay({ onStart }: KdsStartOverlayProps) {
    return (
        <div className="fixed inset-0 z-[2000] flex items-center justify-center p-6" style={{ background: 'rgba(0,0,0,0.88)' }}>
            <button
                type="button"
                onClick={onStart}
                className="kds-press flex min-h-[48px] min-w-[280px] flex-col items-center justify-center gap-4 rounded-3xl border-4 px-12 py-10 text-white"
                style={{ borderColor: kdsTokens.ack, background: kdsTokens.card }}
            >
                <span className="text-5xl font-black leading-none">اضغط للبدء</span>
                <span className="text-xl font-bold" style={{ color: kdsTokens.noteStrong }}>
                    لتفعيل الصوت وإبقاء الشاشة مضاءة
                </span>
            </button>
        </div>
    );
}
