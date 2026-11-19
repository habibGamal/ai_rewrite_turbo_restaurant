---
name: inventory_health
title: Inventory health
description: Stock check: items running low, dead stock, stocktaking differences, waste by reason, loss rate and purchase cost increases.
requires: inventory_status, inventory_movements
---
Check the health of the stock. Use stock as it is now, and the last 30 days for movements (period=last_30_days).

Steps:
1. inventory_status view=low, limit=15: items at or below their minimum, with days of cover.
2. inventory_status view=dead, limit=10: stock with no sale for 30 days or more, by value.
3. inventory_status view=value: stock value per warehouse.
4. inventory_movements view=summary: purchases, consumption, waste, loss and loss rate, against the previous period.
5. inventory_movements view=losses, then view=waste_reasons.
6. inventory_movements view=cost_changes: items whose cost went up.
7. inventory_movements view=alerts: data problems such as negative balances or zero-cost movements. Mention them only when found.
8. If available: purchases_suppliers for the period, to see the top suppliers and items behind the cost changes.

Answer shape:
- Headline: total stock value and the loss rate, with its change.
- "Order now": the low items with the fewest days of cover.
- "Money sitting still": the dead stock with the highest value.
- "Losses": the top loss items and waste reasons.
- "Costs": the biggest cost increases.
- One line on data alerts, if there are any.
