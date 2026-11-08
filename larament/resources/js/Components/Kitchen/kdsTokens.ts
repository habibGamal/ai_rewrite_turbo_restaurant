import type { CSSProperties } from 'react';

/**
 * Wall-KDS palette. Dark surfaces, AA text, one accent per state.
 * Defined once and exposed as CSS variables so cards, the bar and drawers share it.
 */
export const kdsTokens = {
    page: '#0b0e14',
    bar: '#10141c',
    line: 'rgba(255,255,255,0.08)',
    card: '#161b24',
    cardAlt: '#1c2430',
    text: '#f5f7fb',
    textSoft: '#c5ceda',
    textDim: '#9aa6b5',
    stripNormal: '#3d4c63',
    stripWarning: '#f59e0b',
    stripLate: '#ef4444',
    timer: '#f5f7fb',
    timerWarning: '#fbbf24',
    timerLate: '#fecaca',
    fresh: '#34d399',
    freshInk: '#052e16',
    rush: '#6d28d9',
    started: '#38bdf8',
    note: '#fbbf24',
    noteStrong: '#fde68a',
    ack: '#f59e0b',
    ackInk: '#111827',
    voidTag: '#9ca3af',
    voidAfter: '#f87171',
    increase: '#6ee7b7',
    reduce: '#fca5a5',
    qtyBg: '#243044',
    qtyInk: '#f8fafc',
    done: '#15803d',
    doneInk: '#ffffff',
    check: '#4ade80',
    cancelled: '#2a1518',
    cancelledBanner: '#9f1239',
    highlight: '#facc15',
    danger: '#ef4444',
    barHeight: 56,
    footerHeight: 52,
    markerHeight: 22,
    gap: 10,
    touch: 48,
    radius: 14,
} as const;

export const kdsCssVars = {
    '--kds-page': kdsTokens.page,
    '--kds-bar': kdsTokens.bar,
    '--kds-line': kdsTokens.line,
    '--kds-card': kdsTokens.card,
    '--kds-card-alt': kdsTokens.cardAlt,
    '--kds-text': kdsTokens.text,
    '--kds-text-soft': kdsTokens.textSoft,
    '--kds-text-dim': kdsTokens.textDim,
    '--kds-fresh': kdsTokens.fresh,
    '--kds-fresh-ink': kdsTokens.freshInk,
    '--kds-rush': kdsTokens.rush,
    '--kds-started': kdsTokens.started,
    '--kds-note': kdsTokens.note,
    '--kds-note-strong': kdsTokens.noteStrong,
    '--kds-ack': kdsTokens.ack,
    '--kds-ack-ink': kdsTokens.ackInk,
    '--kds-qty-bg': kdsTokens.qtyBg,
    '--kds-qty-ink': kdsTokens.qtyInk,
    '--kds-done': kdsTokens.done,
    '--kds-done-ink': kdsTokens.doneInk,
    '--kds-check': kdsTokens.check,
    '--kds-cancelled': kdsTokens.cancelled,
    '--kds-cancelled-banner': kdsTokens.cancelledBanner,
    '--kds-danger': kdsTokens.danger,
    '--kds-highlight': kdsTokens.highlight,
    background: kdsTokens.page,
    color: kdsTokens.text,
} as CSSProperties;

export function kdsPx(base: number, scale: number): number {
    return Math.round(base * Math.max(0.5, scale));
}

export const KDS_CSS = `
@keyframes kds-late-pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.55; }
}
@keyframes kds-ack-pulse {
  0% { background-color: rgba(245, 158, 11, 0.22); }
  100% { background-color: transparent; }
}
@keyframes kds-enter {
  from { opacity: 0; }
  to { opacity: 1; }
}
.kds-late-timer { animation: kds-late-pulse 1.6s ease-in-out infinite; }
.kds-ack-once { animation: kds-ack-pulse 700ms ease-out 1; }
.kds-card-enter { animation: kds-enter 180ms ease-out; }
.kds-press:active { filter: brightness(1.15); }
.kds-search::placeholder { color: #9aa6b5; }
.kds-search:focus { outline: 2px solid #f59e0b; outline-offset: 1px; }
@media (prefers-reduced-motion: reduce) {
  .kds-late-timer, .kds-ack-once, .kds-card-enter { animation: none; }
}
`;
