import { useKds } from '@/Components/Kitchen/kdsContext';
import KdsBlock from '@/Components/Kitchen/KdsTicketBlocks';
import { CARD_CHROME_HEIGHT, CONTINUATION_MARKER_HEIGHT, type PackedFragment } from '@/Components/Kitchen/packTickets';
import { ticketUrgency } from '@/Components/Kitchen/ticketModel';
import type { KdsTicket } from '@/types/kitchen';

interface KdsTicketFragmentProps {
    ticket: KdsTicket;
    fragment: PackedFragment;
}

export default function KdsTicketFragment({ ticket, fragment }: KdsTicketFragmentProps) {
    const { nowMs, kdsSettings, highlightedId, acknowledge } = useKds();
    const urgency = ticketUrgency(ticket, nowMs, kdsSettings.warning_percent);
    const cancelled = ticket.status === 'cancelled';
    const highlighted = highlightedId === ticket.id;
    const borderColor = cancelled
        ? '#ef4444'
        : urgency === 'late'
          ? '#ef4444'
          : urgency === 'warning'
            ? '#f59e0b'
            : '#3f3f46';
    const shadows: string[] = [];
    if (ticket.is_rush) {
        shadows.push('0 0 0 3px #7c3aed');
    }
    if (highlighted) {
        shadows.push('0 0 0 4px #facc15');
    }

    return (
        <div className="shrink-0">
            {fragment.continued && <ContinuationMarker ticket={ticket} />}
            <article
                data-kds-ticket={ticket.id}
                data-order-number={ticket.order_number}
                className="relative box-border overflow-hidden rounded-xl bg-zinc-900"
                style={{
                    borderWidth: CARD_CHROME_HEIGHT / 2,
                    borderStyle: 'solid',
                    borderColor,
                    boxShadow: shadows.length > 0 ? shadows.join(', ') : undefined,
                }}
            >
                {fragment.blockKeys.map((blockKey) => (
                    <KdsBlock key={blockKey} ticket={ticket} blockKey={blockKey} />
                ))}
                {cancelled && (
                    <div className="absolute inset-0 z-10 flex flex-col items-center justify-center gap-2 bg-red-950/80 p-2 text-center">
                        <div className="text-3xl font-black text-white">الطلب ملغي</div>
                        <button
                            type="button"
                            className="min-h-12 min-w-36 rounded-xl bg-white px-4 text-xl font-black text-red-700"
                            onClick={() => acknowledge(ticket)}
                        >
                            تم الاطلاع
                        </button>
                    </div>
                )}
            </article>
            {fragment.bottomMarker && <ContinuationMarker ticket={ticket} />}
        </div>
    );
}

function ContinuationMarker({ ticket }: { ticket: KdsTicket }) {
    const cancelled = ticket.status === 'cancelled';
    return (
        <div
            className="flex items-center justify-center overflow-hidden px-2 font-black"
            style={{
                height: CONTINUATION_MARKER_HEIGHT,
                background: cancelled ? '#dc2626' : ticket.is_rush ? '#7c3aed' : '#fbbf24',
                color: cancelled || ticket.is_rush ? '#ffffff' : '#111827',
            }}
        >
            <span className="truncate">
                يتبع #{ticket.order_number}
                {cancelled ? ' — الطلب ملغي' : ''}
            </span>
        </div>
    );
}
