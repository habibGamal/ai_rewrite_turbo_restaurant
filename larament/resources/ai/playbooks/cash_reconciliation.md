---
name: cash_reconciliation
title: Cash reconciliation
description: Shifts with cash shortages or surpluses, who opened them, and patterns by cashier or sales point, with the audit trail behind them.
requires: shifts, staff_performance, audit_events
---
Find where cash in the drawer did not match what the system expected. Default period: this month (period=this_month).

Steps:
1. shifts for the period with limit=50. Keep the shifts where the cash difference is not zero. Note who opened each one, the sales point when shown, and the difference.
2. If a shift is open now, report its expected cash separately. An open shift has no difference yet.
3. staff_performance for the period, rank_by=discount and then rank_by=cancelled. Look for the same people who appear in the shifts with differences.
4. audit_events for the period with event_types=[record_change, refund, cancellation]. Look for edits to closed-shift money and for refunds or cancellations close to the shifts with differences.

Answer shape:
- Headline: how many shifts had a shortage, how many had a surplus, and the totals as the tool returned them.
- A short list of the worst shifts: date, cashier, difference.
- Patterns: the same cashier, sales point or weekday showing up repeatedly. Only claim a pattern when at least 2 shifts show it.
- What to check next, in plain words: recount, review refunds, ask the cashier.
Never accuse a person. Present the numbers and say what they suggest.
