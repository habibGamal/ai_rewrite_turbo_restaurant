---
name: staffing_by_hour
title: Staffing by hour
description: Busy and quiet hours and weekdays, to plan staff and shift times.
requires: sales_breakdown
---
Help the owner plan staff. Default period: the last 30 days (period=last_30_days). Use last_90_days if the user wants a steadier picture.

Steps:
1. sales_breakdown group_by=weekday: orders and net sales per weekday.
2. sales_breakdown group_by=hour: orders and net sales per hour, across all days.
3. For the two busiest weekdays and the quietest one: sales_breakdown group_by=hour with weekday=<that day>.
4. If available: sales_breakdown group_by=channel, to see whether delivery or dine-in drives the peak.

Answer shape:
- Headline: the peak hours and the peak weekday, with their share of orders.
- A compact table of hours (only hours with orders) and their orders for a typical day. Use the busiest weekday if it differs a lot from the rest.
- 3 to 4 recommendations: when to add staff, when one person is enough, and the best time for prep or deliveries. Tie each one to an hour or weekday and its number.
