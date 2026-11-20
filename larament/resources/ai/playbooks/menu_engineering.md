---
name: menu_engineering
title: Menu engineering
description: Classify items into stars, plowhorses, puzzles and dogs by volume and margin within each category, with what to do about each group.
requires: product_performance, product_detail
---
Run a menu-engineering review. Default period: the last 90 days (period=last_90_days), so seasonal noise evens out.

Steps:
1. product_performance level=main_category to get the categories and their shares.
2. For each main category that holds at least 5% of sales (at most 6 categories): call product_performance with category=<name>, rank_by=net_quantity, limit=30. Then call it again with rank_by=margin.
3. Within each category only, place each item by volume (net quantity against the category's middle item) and by margin (against the category's middle margin):
   - Star: high volume, high margin. Keep it, protect quality, and feature it.
   - Plowhorse: high volume, low margin. Check its cost or recipe, or raise the price a little.
   - Puzzle: low volume, high margin. Promote it, move it up the menu, or rename it.
   - Dog: low volume, low margin. Candidate to drop or rework.
   Base every placement on the numbers the tool returned. Never compare items across categories. Never compare a family tray with a single meal.
4. For the 2 or 3 most surprising items (for example a dog with high sales value, or a plowhorse with negative profit), call product_detail to check cost changes and the weekly trend.
5. product_performance rank_by=no_sales: items that sold nothing are candidates to remove.

Answer shape: one short section per category with the four groups, each item followed by its quantity and margin as the tool returned them. Then 3 to 5 concrete actions. Close with one line naming the period.
