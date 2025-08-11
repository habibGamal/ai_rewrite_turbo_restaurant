# Database Structure Fix - CustomersPerformanceReport

## Issue Identified
The original implementation incorrectly referenced `orders.delivery_cost` which doesn't exist in the database schema.

## Database Schema Analysis
After reviewing the migration files:

### Orders Table (`2025_07_07_000009_create_orders_table.php`)
- **Does NOT have**: `delivery_cost` field
- **Has**: id, customer_id, driver_id, user_id, shift_id, status, type, sub_total, tax, service, discount, temp_discount_percent, total, profit, payment_status, dine_table_number, kitchen_notes, order_notes, order_number

### Customers Table (`2025_07_07_000007_create_customers_table.php`)
- **Has**: `delivery_cost` field (default delivery cost for the customer)
- **Fields**: id, name, phone, has_whatsapp, address, region, delivery_cost

## Changes Made

### 1. `CustomersPerformanceReportService.php`
- **Removed**: `DB::raw('COALESCE(SUM(orders.delivery_cost), 0) as total_delivery_fees')` from `getCustomersPerformanceQuery()`
- **Removed**: `total_delivery_fees` from `getPeriodSummary()` query and return array
- **Fixed**: `avg_order_value` calculation to properly divide total sales by number of orders
- **Improved**: SQL calculations to handle division by zero cases

### 2. `CustomersPerformanceStatsWidget.php`
- **Removed**: Delivery fees stat widget (7th stat)
- **Now shows**: 10 key metrics instead of 11
- **Stats remain**:
  1. Total customers
  2. Total orders  
  3. Total sales
  4. Total profits
  5. Average order value
  6. Average orders per customer
  7. Top customer by sales
  8. Top customer by profit
  9. Most frequent customer
  10. Highest average order value customer

### 3. `CustomersPerformanceTableWidget.php`
- **Removed**: `total_delivery_fees` column from table
- **Columns remain**: name, phone, region, total_orders, total_quantity, total_sales, total_profit, avg_order_value, profit_margin, customer_segment, dates, order type breakdowns

### 4. `CustomersPerformanceTableExporter.php`
- **Removed**: `total_delivery_fees` column from export
- **Excel export**: Still includes all other customer performance metrics

## Technical Notes

### Average Order Value Calculation
- **Current approach**: `SUM(order_items.total) / COUNT(DISTINCT orders.id)`
- **Rationale**: This gives the average total value per order for each customer
- **Alternative considered**: Using `orders.total` would include taxes/service charges, but requires different join structure

### Delivery Cost Handling
- **Customer default**: Available in `customers.delivery_cost` 
- **Per-order delivery cost**: Not stored in current schema
- **Future enhancement**: Could calculate estimated delivery fees based on order type and customer defaults

### Data Integrity
- **Grouping**: Maintained proper grouping by customer fields
- **Aggregations**: All other calculations remain accurate
- **Performance**: Query performance should be similar or slightly better without the delivery cost field

## Impact Assessment
- **Functionality**: Core customer performance analytics fully functional
- **UI**: Slightly cleaner interface without non-existent delivery fee metrics
- **Data accuracy**: More accurate since we're not referencing non-existent fields
- **Export**: Excel exports work correctly without delivery cost data

## Future Considerations
If delivery cost tracking per order is needed:
1. Add `delivery_cost` field to orders table via migration
2. Update order creation logic to populate this field
3. Re-add delivery cost tracking to reports
