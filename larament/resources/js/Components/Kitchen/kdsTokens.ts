import type { CSSProperties } from 'react';

/**
 * Wall-KDS palette. High-contrast dark surfaces, crisp borders, one semantic accent per state.
 * Exposed as CSS variables so cards, top bar, panels and drawers share a unified visual language.
 */
export const kdsTokens = {
    page: '#090c12',
    bar: '#0f141e',
    line: 'rgba(255,255,255,0.08)',
    lineStrong: 'rgba(255,255,255,0.14)',
    card: '#141b27',
    cardElevated: '#192231',
    cardAlt: '#1e293b',
    text: '#f8fafc',
    textSoft: '#cbd5e1',
    textDim: '#94a3b8',
    stripNormal: '#3b82f6',
    stripWarning: '#f59e0b',
    stripLate: '#ef4444',
    timer: '#f8fafc',
    timerWarning: '#fbbf24',
    timerLate: '#fecaca',
    fresh: '#34d399',
    freshInk: '#052e16',
    rush: '#7c3aed',
    rushSoft: 'rgba(124, 58, 237, 0.22)',
    note: '#fbbf24',
    noteStrong: '#fef3c7',
    ack: '#f59e0b',
    ackInk: '#111827',
    voidTag: '#9ca3af',
    voidAfter: '#f87171',
    increase: '#6ee7b7',
    reduce: '#fca5a5',
    qtyBg: '#253349',
    qtyInk: '#f8fafc',
    done: '#16a34a',
    doneInk: '#ffffff',
    check: '#4ade80',
    cancelled: '#241217',
    cancelledBanner: '#be123c',
    highlight: '#facc15',
    danger: '#ef4444',
    barHeight: 60,
    footerHeight: 56,
    gap: 12,
    touch: 48,
    radius: 16,
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
  50% { opacity: 0.52; }
}
@keyframes kds-ack-pulse {
  0% { background-color: rgba(245, 158, 11, 0.24); }
  100% { background-color: transparent; }
}
@keyframes kds-enter {
  from { opacity: 0; transform: translateY(6px) scale(0.985); }
  to { opacity: 1; transform: translateY(0) scale(1); }
}
@keyframes kds-highlight-ring {
  0%, 100% { box-shadow: 0 0 0 3px #facc15, 0 0 24px rgba(250, 204, 21, 0.35); }
  50% { box-shadow: 0 0 0 4px #fde047, 0 0 32px rgba(250, 204, 21, 0.55); }
}
.kds-late-timer { animation: kds-late-pulse 1.5s ease-in-out infinite; }
.kds-ack-once { animation: kds-ack-pulse 750ms ease-out 1; }
.kds-card-enter { animation: kds-enter 200ms cubic-bezier(0.22, 1, 0.36, 1); }
.kds-card-highlight { animation: kds-highlight-ring 1s ease-in-out infinite; }
.kds-press { transition: transform 80ms ease, filter 120ms ease, background-color 140ms ease; }
.kds-press:active { transform: scale(0.98); filter: brightness(1.12); }
.kds-search::placeholder { color: #94a3b8; }
.kds-search:focus { outline: 2px solid #f59e0b; outline-offset: 1px; }
.kds-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
.kds-scroll::-webkit-scrollbar-track { background: transparent; }
.kds-scroll::-webkit-scrollbar-thumb { background: rgba(148, 163, 184, 0.25); border-radius: 9999px; }
.kds-scroll::-webkit-scrollbar-thumb:hover { background: rgba(148, 163, 184, 0.45); }
@media (prefers-reduced-motion: reduce) {
  .kds-late-timer, .kds-ack-once, .kds-card-enter, .kds-card-highlight { animation: none; }
  .kds-press { transition: none; }
}
`;
