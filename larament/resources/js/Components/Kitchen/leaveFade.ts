const FADE_MS = 200;

/** Clone the on-screen card and fade it out (≤ 250ms) while the board reflows. */
export function fadeLeavingTicket(ticketId: number): void {
    if (typeof document === 'undefined') {
        return;
    }
    const nodes = document.querySelectorAll(`[data-kds-ticket="${ticketId}"]`);
    nodes.forEach((node) => {
        if (!(node instanceof HTMLElement)) {
            return;
        }
        const rect = node.getBoundingClientRect();
        if (rect.width < 8 || rect.height < 8) {
            return;
        }
        const clone = node.cloneNode(true);
        if (!(clone instanceof HTMLElement)) {
            return;
        }
        clone.removeAttribute('data-kds-ticket');
        clone.style.position = 'fixed';
        clone.style.top = `${rect.top}px`;
        clone.style.left = `${rect.left}px`;
        clone.style.width = `${rect.width}px`;
        clone.style.height = `${rect.height}px`;
        clone.style.margin = '0';
        clone.style.zIndex = '40';
        clone.style.pointerEvents = 'none';
        clone.style.opacity = '1';
        clone.style.transition = `opacity ${FADE_MS}ms ease`;
        document.body.appendChild(clone);
        window.requestAnimationFrame(() => {
            clone.style.opacity = '0';
        });
        window.setTimeout(() => clone.remove(), FADE_MS + 40);
    });
}
