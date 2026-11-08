import { useKds } from '@/Components/Kitchen/kdsContext';
import KdsBlock from '@/Components/Kitchen/KdsTicketBlocks';
import { kdsTokens } from '@/Components/Kitchen/kdsTokens';
import { CARD_CHROME_HEIGHT, CONTINUATION_MARKER_HEIGHT, type PackedFragment } from '@/Components/Kitchen/packTickets';
import { ticketUrgency } from '@/Components/Kitchen/ticketModel';
import type { KdsTicket } from '@/types/kitchen';

interface KdsTicketFragmentProps {
    ticket: KdsTicket;
    fragment: PackedFragment;
}

export default function KdsTicketFragment({ ticket, fragment }: KdsTicketFragmentProps) {
    const { nowMs, kdsSettings, highlightedId } = useKds();
    const urgency = ticketUrgency(ticket, nowMs, kdsSettings.warning_percent);
    const cancelled = ticket.status === 'cancelled';
    const highlighted = highlightedId === ticket.id;
    const accent = cancelled
        ? kdsTokens.stripLate
        : urgency === 'late'
          ? kdsTokens.stripLate
          : urgency === 'warning'
            ? kdsTokens.stripWarning
            : kdsTokens.stripNormal;
    const shadows = ['inset 0 0 0 1px rgba(255,255,255,0.06)'];
    if (highlighted) {
        shadows.push(`0 0 0 3px ${kdsTokens.highlight}`);
    }

    return (
        <div className="kds-card-enter shrink-0" data-kds-ticket={ticket.id}>
            {fragment.continued && <ContinuationMarker ticket={ticket} edge="top" />}
            <article
                data-order-number={ticket.order_number}
                className="relative box-border overflow-hidden"
                style={{
                    borderTopWidth: CARD_CHROME_HEIGHT,
                    borderTopStyle: 'solid',
                    borderTopColor: accent,
                    borderRadius: kdsTokens.radius,
                    background: cancelled ? kdsTokens.cancelled : kdsTokens.card,
                    boxShadow: shadows.join(', '),
                }}
            >
                {fragment.blockKeys.map((blockKey) => (
                    <KdsBlock key={blockKey} ticket={ticket} blockKey={blockKey} />
                ))}
            </article>
            {fragment.bottomMarker && <ContinuationMarker ticket={ticket} edge="bottom" />}
        </div>
    );
}

function ContinuationMarker({ ticket, edge }: { ticket: KdsTicket; edge: 'top' | 'bottom' }) {
    return (
        <div
            className="flex items-center justify-center overflow-hidden px-2 text-xs font-bold"
            style={{
                height: CONTINUATION_MARKER_HEIGHT,
                lineHeight: `${CONTINUATION_MARKER_HEIGHT}px`,
                color: kdsTokens.textDim,
            }}
        >
            {edge === 'bottom' ? (
                <span>
                    يتبع <span dir="ltr">←</span>
                </span>
            ) : (
                <span>تابع #{ticket.order_number}</span>
            )}
        </div>
    );
}
