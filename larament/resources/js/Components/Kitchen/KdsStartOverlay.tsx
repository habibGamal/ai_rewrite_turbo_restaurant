interface KdsStartOverlayProps {
    onStart: () => void;
}

export default function KdsStartOverlay({ onStart }: KdsStartOverlayProps) {
    return (
        <div className="fixed inset-0 z-[2000] flex items-center justify-center bg-black/90 p-6">
            <button
                type="button"
                onClick={onStart}
                className="flex min-h-[48px] min-w-[280px] flex-col items-center justify-center gap-4 rounded-3xl border-4 border-amber-400 bg-zinc-900 px-12 py-10 text-white shadow-2xl"
            >
                <span className="text-5xl font-black leading-none">اضغط للبدء</span>
                <span className="text-xl font-bold text-amber-200">لتفعيل الصوت وإبقاء الشاشة مضاءة</span>
            </button>
        </div>
    );
}
