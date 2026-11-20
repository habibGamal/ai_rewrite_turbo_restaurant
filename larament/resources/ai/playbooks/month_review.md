---
name: month_review
title: Month review
description: Review of a month (default last complete month) against the month before and the same month last year, and what drove the change.
requires: get_business_context, sales_summary, sales_breakdown, product_performance
---
Review one month for the owner. Default: the last complete month (period=last_month). If the user names a month, pass its from/to dates.

Steps, in this order:
1. get_business_context.
2. sales_summary for the month, compare_to=previous_period.
3. sales_summary for the month, compare_to=same_period_last_year. If last year has no data, say so in one line.
4. sales_breakdown group_by=channel: which channels grew or shrank.
5. sales_breakdown group_by=weekday: the strongest and weakest weekdays.
6. product_performance rank_by=net_sales, limit=10. Then product_performance level=main_category to see category shares.
7. product_performance rank_by=margin, limit=5, plus rank_by=lowest_sales, limit=5: items that pull profit down.
8. If available: expenses_revenues for the month. Report the biggest expense types and the net after expenses.

Answer shape:
- Headline: net sales, profit and margin for the month, with the change against the previous month and against last year.
- "What drove it": 3 to 5 bullets, each tied to a channel, weekday, category or item, with its number.
- "Watch": 1 to 3 bullets on weak items, rising expenses or a falling channel.
- One closing line with the month and the comparison periods used.
