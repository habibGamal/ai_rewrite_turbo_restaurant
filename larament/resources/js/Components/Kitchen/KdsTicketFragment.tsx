import { useKds } from '@/Components/Kitchen/kdsContext';
import KdsBlock from '@/Components/Kitchen/KdsTicketBlocks';
import { kdsTokens } from '@/Components/Kitchen/kdsTokens';
import { CARD_CHROME_HEIGHT, CONTINUATION_MARKER_HEIGHT, type PackedFragment } from '@/Components/Kitchen/packTickets';
import { ticketUrgency } from '@/Components/Kitchen/ticketModel';
import type { KdsTicket } from '@/types/kitchen';
import { memo } from 'react';

interface KdsTicketFragmentProps {
    ticket: KdsTicket;
    fragment: PackedFragment;
}

const KdsTicketFragment = memo(function KdsTicketFragment({ ticket, fragment }: KdsTicketFragmentProps) {
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

    const borderColor = cancelled
        ? 'rgba(244, 63, 94, 0.45)'
        : urgency === 'late'
          ? 'rgba(239, 68, 68, 0.45)'
          : urgency === 'warning'
            ? 'rgba(245, 158, 11, 0.35)'
            : kdsTokens.lineStrong;

    const shadows = [`inset 0 0 0 1px ${borderColor}`, '0 8px 24px rgba(0, 0, 0, 0.28)'];
    if (highlighted) {
        shadows.push(`0 0 0 3px ${kdsTokens.highlight}`);
    }

    return (
        <div className="kds-card-enter shrink-0" data-kds-ticket={ticket.id}>
            {fragment.continued && <ContinuationMarker ticket={ticket} edge="top" />}
            <article
                data-order-number={ticket.order_number}
                className={`relative box-border overflow-hidden ${highlighted ? 'kds-card-highlight' : ''}`}
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
});

export default KdsTicketFragment;

function ContinuationMarker({ ticket, edge }: { ticket: KdsTicket; edge: 'top' | 'bottom' }) {
    return (
        <div
            className="flex items-center justify-center overflow-hidden px-2 text-xs font-black"
            style={{
                height: CONTINUATION_MARKER_HEIGHT,
                lineHeight: `${CONTINUATION_MARKER_HEIGHT}px`,
                color: kdsTokens.timerWarning,
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
