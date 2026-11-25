# Domain Glossary

Canonical mapping between English code names, Arabic UI terms (from `larament/lang/ar.json` and Filament/order-type labels), English meanings, and Modern Standard Arabic meanings for the user glossary.

| English code name | Arabic UI word | Meaning | المعنى بالعربية |
|---|---|---|---|
| `terminal` (`Terminal`) | منفذ بيع (never درج) | Cashier workstation / sales point with its own opening cash, session, and printer. | جهاز كاشير مستقل داخل الوردية له نقدية افتتاحية وجلسة وطابعة خاصة. |
| `shift` (`Shift`) | وردية | Cashier session from open to close, tracking cash float, sales, and expenses. | فترة العمل من فتح الوردية إلى إغلاقها، وتُسجّل فيها النقدية والمبيعات والمصروفات. |
| `branch` (`slave` node) | فرع | Branch POS install synced with a master node or central platform. | نسخة نقطة بيع لفرع تتزامن مع النقطة الرئيسية أو مع المنصة المركزية. |
| `web_order` (`WebOrders`) | طلب الموقع | Online order received from the linked `turbo_tenant` website. | طلب وارد عبر الإنترنت من موقع الطلبات المرتبط بالمتجر. |
| `tenant` (`turbo_tenant`) | مستأجر | Brand/store instance on the online ordering website. | حساب المتجر أو العلامة التجارية على موقع الطلبات الإلكتروني. |
| `order` (`Order`) | طلب | Customer order (`pending`, `processing`, `out_for_delivery`, `completed`, `cancelled`). | طلب عميل بمراحله من الانتظار والتحضير حتى الإنهاء أو الإلغاء. |
| `order_type` (`OrderTypeRow`) | نوع طلب | Order channel row (`dine_in` صالة, `takeaway` تيك أواي, `delivery` دليفري, `companies` شركات, `talabat` طلبات, `web_delivery` اونلاين دليفري, `web_takeaway` اونلاين تيك أواي, `instant_sale` بيع مباشر). | قناة بيع داخل شاشة الكاشير مثل صالة أو تيك أواي أو دليفري أو بيع مباشر. |
| `dine_table` / floor (`DineTable`) | طاولة / مخطط الصالة | Dine-in table occupancy row and per-type JSON floor layout. | طاولات الصالة وحالتها ومخطط توزيعها داخل المطعم أو الكافيه. |
| `kitchen` / `KDS` (`KitchenStation`, `KitchenTicket`) | شاشة المطبخ / محطة مطبخ | Live Kitchen Display System screen and preparation stations. | شاشة المطبخ الحية ومحطات تحضير الأصناف في المطعم. |
| `product` (`Product`) | منتج / صنف | Sellable item or inventory material (`Manufactured` منتج مُصنع, `Consumable` منتج استهلاكي, `RawMaterial` مادة خام). | سلعة تُباع أو مادة في المخزون: منتج مُصنع أو استهلاكي أو مادة خام. |
| `product_group` (`ProductGroup`) | موديل | Variant group combining axes such as size and colour into SKUs. | مجموعة أصناف تجمع المقاسات والألوان تحت منتج واحد برموز باركود مستقلة. |
| `category` (`Category`) | فئة | Product category (supports parent/sub-category hierarchy and KDS/warehouse routing). | تصنيف للمنتجات يدعم الفئات الفرعية والتوجيه إلى المطبخ والمخزن. |
| `inventory_item` / stock (`InventoryItem`) | عنصر مخزون / المخزون | Current stock balance per product and warehouse. | رصيد المخزون الحالي لكل صنف داخل كل مخزن. |
| `warehouse` (`Warehouse`) | مخزن | Storage location holding stock balances and receiving transfers/issues. | موقع تخزين يحفظ أرصدة الأصناف ويستقبل التحويلات وأذون الصرف. |
| `stocktaking` (`Stocktaking`) | جرد | Physical inventory count and reconciliation document. | مستند عدّ المخزون الفعلي ومطابقته مع الرصيد المسجل. |
| `waste` (`Waste`) | سجل تالف | Written-off spoiled or damaged inventory document. | مستند تسجيل الأصناف التالفة أو منتهية الصلاحية وخصمها من المخزون. |
| `purchase_invoice` (`PurchaseInvoice`) | فاتورة شراء | Incoming supplier purchase document. | مستند استلام المشتريات من المورد وإضافتها إلى المخزون. |
| `return_purchase_invoice` (`ReturnPurchaseInvoice`) | مرتجع شراء | Outgoing return of purchased stock to a supplier. | مستند إرجاع كميات مشتراة إلى المورد. |
| `customer` (`Customer`) | عميل | Customer record keyed by phone number with delivery region/address and fee. | سجل العميل برقم الهاتف والعنوان والمنطقة ورسوم التوصيل. |
| `driver` (`Driver`) | سائق | Delivery driver assigned to delivery orders. | سائق التوصيل المعيّن لطلبات الدليفري. |
| `region` (`Region`) | منطقة | Delivery area / zone. | نطاق التوصيل الجغرافي ورسوم التوصيل الخاصة به. |
| `expense` (`Expense`) | مصروف | Operational or shift cash expense (`ExpenseCategory`). | مصروف تشغيلي أو نقدي مسجل على الوردية. |
| `revenue` (`Revenue`) | إيراد | Non-order shift revenue (`RevenueType` بند إيراد). | إيراد إضافي غير مرتبط بطلب بيع يُسجّل على الوردية. |
| `payment_method` (`PaymentMethod`) | طريقة الدفع | Tender method (`cash` نقدي, `card` بطاقة, `talabat_card` بطاقة طلبات). | وسيلة السداد المستخدمة مثل نقدي أو بطاقة أو بطاقة طلبات. |
| `order_return` (`OrderReturn`) | مرتجع طلب | Customer sales return and refund (`OrderReturn`). | إرجاع أصناف من فاتورة مبيعات ورد قيمتها للعميل. |
| `supplier` (`Supplier`) | مورد | Stock supplier for purchase invoices. | المورد الذي تُسجّل باسمه فواتير الشراء. |
| `external_seller` (`ExternalSeller`) | بائع خارجي / طلب خارجي (never إسناد) | Partner seller whose items are billed on the host receipt and settled later. | متجر أو بائع متعاون تُضاف أصنافه على الفاتورة نفسها وتُسوّى مستحقاته لاحقاً. |
| `equipment` (`Equipment`) | معدة | Rental/tracked equipment attached to an order. | معدة أو خدمة إضافية بسعر ثابت تُضاف إلى الطلب. |
| `role` (`Role`) | دور | Spatie RBAC role (`الأدوار والصلاحيات`). | دور صلاحيات يحدد الشاشات والإجراءات المسموح بها للمستخدم. |
| `user` (`User`) | مستخدم | Staff account (`المستخدمين`). | حساب موظف أو مدير داخل النظام. |
| `vendor` (`VendorConsole`) | الدعم الفني | Hidden vendor/support account managing business type, modules, and feature flags. | حساب الدعم المسؤول عن تفعيل نوع النشاط والوحدات والمميزات الإضافية. |
| `setting` (`Setting`) | الإعدادات | Database-backed configuration key (`App\Enums\SettingKey`). | إعدادات ضبط النظام المخزنة في لوحة الإدارة. |
