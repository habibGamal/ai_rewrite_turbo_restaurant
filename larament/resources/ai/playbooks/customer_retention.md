---
name: customer_retention
title: Customer retention
description: New against returning customers, segments, best customers, and regulars who stopped ordering and should be contacted.
requires: customer_insights
---
Review the named customers (orders placed under a customer's name or phone). Default period: the last 90 days (period=last_90_days).

Steps:
1. customer_insights view=overview: customers, new against returning, their share of sales, segments.
2. customer_insights view=top, rank_by=sales, limit=10. Then rank_by=orders, limit=10.
3. customer_insights view=at_risk, limit=15: regulars who stopped ordering.
4. For the 2 or 3 most valuable at-risk customers: customer_insights view=customer to see what they used to order.
5. If deferred payment is on (get_business_context shows it): customer_insights view=receivables.

Answer shape:
- Headline: how many customers, the share of sales from returning customers, and the number at risk.
- Best customers: name, orders, sales.
- Customers to call back: name, last order date, usual items. Suggest one simple offer per customer based on what they used to order.
- Receivables, if any.
Never list or ask for phone numbers. Use names only.
