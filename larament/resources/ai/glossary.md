# Turbo POS glossary / مسرد المصطلحات

Every metric the tools return, in English and Arabic. Each tool result also carries its own `definitions` field. When the two differ, the tool's field wins, because it describes that exact result.

## Time and scope / الزمن والنطاق

| Term | المصطلح | Meaning |
|---|---|---|
| period | الفترة | Calendar days in the shop's timezone (Africa/Cairo), the same days the app's reports use. Presets: today, yesterday, this_week, last_week, this_month, last_month, last_7_days, last_30_days, last_90_days, this_year, last_year. |
| business date | تاريخ يوم العمل | The day a shift counts for. A shift that runs past midnight belongs to the day it opened. Inventory figures use the business date of each movement. |
| shift | الوردية | A cashier session from opening to closing, with its own cash count. Shift-based tools (expenses, equipment, fees, delivery) count the shifts that started in the period. |
| sales point | منفذ بيع | A cashier terminal. It is never called a drawer. |
| comparison | المقارنة | previous_period (same length, just before) or same_period_last_year. change_percent = (current − previous) / previous, as a percent; null means there is nothing to compare with. |

## Sales / المبيعات

| Term | المصطلح | Meaning |
|---|---|---|
| orders | الطلبات | Completed orders. Cancelled orders are counted separately and are never sales. |
| gross | إجمالي المبيعات | Sum of completed order totals (after the order discount, with service, tax, fees and equipment). |
| net sales | صافي المبيعات | Gross minus refunds paid in the period. |
| refunds | المرتجعات (المبالغ المستردة) | Money paid back, counted on the day and shift that paid it, not the original order's day. |
| profit / net profit | الربح / صافي الربح | Item value minus item cost at the time of sale, minus the profit lost to refunds. |
| margin | هامش الربح | Net profit / net sales, as a percent. |
| average order | متوسط الطلب | Net sales / orders. |
| discount rate | نسبة الخصم | Discount / item value, as a percent. |
| cancel rate | نسبة الإلغاء | Cancelled / (completed + cancelled), as a percent. |
| channel | قناة البيع | The order type (dine-in, takeaway, delivery, web and so on). |
| share | الحصة | Percent of the total of all rows in scope. |

## Products / الأصناف

| Term | المصطلح | Meaning |
|---|---|---|
| item value | قيمة الأصناف | Line totals before the order-level discount, so product figures add up to more than net sales. |
| net quantity | صافي الكمية | Quantity sold minus quantity returned. |
| unit margin | هامش الوحدة | (Price − current cost) / price, as a percent. |
| ABC class | تصنيف ABC | A = top items making 80% of sales, B = the next 15%, C = the last 5%. |
| recipe | المكونات | Components used per one unit of a manufactured product. |

## Cash / النقدية

| Term | المصطلح | Meaning |
|---|---|---|
| expected cash | النقدية المتوقعة | Start cash + cash payments + cash revenues − expenses − cash refunds (and the other cash flows the shift records). |
| counted cash | النقدية الفعلية | Cash the cashier counted at close. |
| cash difference | فرق النقدية | Counted − expected. Negative is a shortage (عجز), positive a surplus (زيادة). |
| net (expenses report) | الصافي | Order revenue net + other revenues − expenses. |

## Inventory / المخزون

| Term | المصطلح | Meaning |
|---|---|---|
| stock now | الرصيد الحالي | Quantity in stock now, in the product's unit. |
| stock value | قيمة المخزون | Quantity × the product's current cost. |
| movement values | قيم الحركات | At the cost recorded on each movement, so past figures do not change when a cost changes. |
| consumption | الاستهلاك | Stock used by sales, net of sales returns put back. |
| waste | الهالك | Stock written off as waste, with its reason. |
| loss | الفاقد | Waste + stocktaking shortage − stocktaking surplus. |
| loss rate | نسبة الفاقد | Loss / (consumption + stock issues + loss), as a percent. |
| cover days | أيام التغطية | How many days the stock lasts at the recent daily usage. Empty means no usage. |
| low stock | مخزون منخفض | At or below the product's minimum stock. Items without a minimum are never low. |
| dead stock | مخزون راكد | Stock above zero with no sale in the last 30 days. |
| net purchases | صافي المشتريات | Closed purchase invoices minus purchase returns, at invoice value. |

## Customers and staff / العملاء والموظفون

| Term | المصطلح | Meaning |
|---|---|---|
| customers | العملاء | Distinct named customers with a completed order in the period. Walk-in sales have no customer. |
| new customer | عميل جديد | First ever completed order is in the period. |
| at risk | عميل معرض للفقد | 2+ orders, and the time since the last order is well past their usual gap. |
| segments | الشرائح | VIP, loyal, regular and occasional, by the thresholds set in the customers report. |
| staff | الموظف | The user who created the order, or who made the change or refund. |
| items removed | الأصناف المحذوفة | Item quantity removed from orders after they were first saved (sent to the kitchen or printed). |
