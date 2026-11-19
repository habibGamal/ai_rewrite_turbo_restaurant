---
name: daily_briefing
title: Daily briefing
description: One-screen briefing for a day (default yesterday): sales against the day before and the same weekday last week, top items, cash differences, stock running low.
requires: get_business_context, sales_summary, product_performance
---
Give the owner a short briefing for one day. Default day: yesterday. Use today only when the user asks for today, and then say that today's figures can still change.

Steps, in this order:
1. get_business_context: today's date, whether a shift is open, enabled features.
2. sales_summary for the day (period=yesterday or today, compare_to=previous_period).
3. sales_summary again with from = to = the same weekday one week earlier. Report both net sales numbers side by side. Do not compute a difference yourself.
4. product_performance for the day, rank_by=net_sales, limit=5.
5. If available: shifts for the day. Name any shift with a cash difference, who opened it and the difference.
6. If available: inventory_status view=low, limit=5.

Answer shape:
- One headline line: net sales and orders for the day, with the change against the day before.
- 3 to 5 bullets: same weekday last week, the top 3 items, cancellations or refunds if they are not zero, cash differences, items running low.
- Close with one line naming the day covered.
Skip a bullet when its tool is missing or shows nothing worth mentioning. Do not pad.
