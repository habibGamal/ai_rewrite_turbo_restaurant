# Domain Glossary

Canonical mapping between English code names, Arabic UI terms (from `larament/lang/ar.json` and Filament/order-type labels), and their meanings.

| English code name | Arabic UI word | Meaning |
|---|---|---|
| `terminal` (`Terminal`) | منفذ بيع (never درج) | Cashier workstation / sales point with its own opening cash, session, and printer. |
| `shift` (`Shift`) | وردية | Cashier session from open to close, tracking cash float, sales, and expenses. |
| `branch` (`slave` node) | فرع | Branch POS install synced with a master node or central platform. |
| `web_order` (`WebOrders`) | طلب الموقع | Online order received from the linked `turbo_tenant` website. |
| `tenant` (`turbo_tenant`) | مستأجر | Brand/store instance on the online ordering website. |
| `order` (`Order`) | طلب | Customer order (`pending`, `processing`, `out_for_delivery`, `completed`, `cancelled`). |
| `order_type` (`OrderTypeRow`) | نوع طلب | Order channel row (`dine_in` صالة, `takeaway` تيك أواي, `delivery` دليفري, `companies` شركات, `talabat` طلبات, `web_delivery` اونلاين دليفري, `web_takeaway` اونلاين تيك أواي, `instant_sale` بيع مباشر). |
| `dine_table` / floor (`DineTable`) | طاولة / مخطط الصالة | Dine-in table occupancy row and per-type JSON floor layout. |
| `kitchen` / `KDS` (`KitchenStation`, `KitchenTicket`) | شاشة المطبخ / محطة مطبخ | Live Kitchen Display System screen and preparation stations. |
| `product` (`Product`) | منتج / صنف | Sellable item or inventory material (`Manufactured` منتج مُصنع, `Consumable` منتج استهلاكي, `RawMaterial` مادة خام). |
| `product_group` (`ProductGroup`) | موديل | Variant group combining axes such as size and colour into SKUs. |
| `category` (`Category`) | فئة | Product category (supports parent/sub-category hierarchy and KDS/warehouse routing). |
| `inventory_item` / stock (`InventoryItem`) | عنصر مخزون / المخزون | Current stock balance per product and warehouse. |
| `warehouse` (`Warehouse`) | مخزن | Storage location holding stock balances and receiving transfers/issues. |
| `stocktaking` (`Stocktaking`) | جرد | Physical inventory count and reconciliation document. |
| `waste` (`Waste`) | سجل تالف | Written-off spoiled or damaged inventory document. |
| `purchase_invoice` (`PurchaseInvoice`) | فاتورة شراء | Incoming supplier purchase document. |
| `return_purchase_invoice` (`ReturnPurchaseInvoice`) | مرتجع شراء | Outgoing return of purchased stock to a supplier. |
| `customer` (`Customer`) | عميل | Customer record keyed by phone number with delivery region/address and fee. |
| `driver` (`Driver`) | سائق | Delivery driver assigned to delivery orders. |
| `region` (`Region`) | منطقة | Delivery area / zone. |
| `expense` (`Expense`) | مصروف | Operational or shift cash expense (`ExpenseCategory`). |
| `revenue` (`Revenue`) | إيراد | Non-order shift revenue (`RevenueType` بند إيراد). |
| `payment_method` (`PaymentMethod`) | طريقة الدفع | Tender method (`cash` نقدي, `card` بطاقة, `talabat_card` بطاقة طلبات). |
| `order_return` (`OrderReturn`) | مرتجع طلب | Customer sales return and refund (`OrderReturn`). |
| `supplier` (`Supplier`) | مورد | Stock supplier for purchase invoices. |
| `external_seller` (`ExternalSeller`) | بائع خارجي / طلب خارجي (never إسناد) | Partner seller whose items are billed on the host receipt and settled later. |
| `equipment` (`Equipment`) | معدة | Rental/tracked equipment attached to an order. |
| `role` (`Role`) | دور | Spatie RBAC role (`الأدوار والصلاحيات`). |
| `user` (`User`) | مستخدم | Staff account (`المستخدمين`). |
| `vendor` (`VendorConsole`) | الدعم الفني | Hidden vendor/support account managing business type, modules, and feature flags. |
| `setting` (`Setting`) | الإعدادات | Database-backed configuration key (`App\Enums\SettingKey`). |
