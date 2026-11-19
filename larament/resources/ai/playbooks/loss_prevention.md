---
name: loss_prevention
title: Loss prevention
description: Signs of leakage: cancellations, items removed after printing, heavy discounts, refunds, and waste or stocktaking shortages.
requires: staff_performance, audit_events
---
Look for money or stock leaking out of the business. Default period: the last 30 days (period=last_30_days).

Steps:
1. staff_performance rank_by=discount, then rank_by=cancelled, then rank_by=items_removed. Keep the top names in each list.
2. audit_events event_types=[discount, cancellation, item_removed], limit=30. Look for large discounts, cancellations late in a shift, and repeated removals by the same person.
3. If available: returns_analysis group_by=cashier and group_by=reason.
4. If available: inventory_movements view=summary for the loss rate, then view=losses and view=waste_reasons.
5. If available: sales_summary for the period, for the scale of cancellations and refunds against net sales.

Answer shape:
- Headline: the one or two biggest leaks found, with their value.
- Bullets by area (discounts, cancellations, removed items, refunds, waste and shortages), each with the number and the person, item or reason behind it. Skip an area with nothing unusual.
- 2 or 3 controls to add, for example manager approval for discounts above a limit, or a daily waste log.
Present facts, not accusations. One person with high numbers may simply work the most shifts, so compare against their sales from staff_performance.
