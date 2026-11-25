# Modules & business types

The POS is one codebase. What a shop sees depends on three switches, all owned by the [vendor account](/dev/vendor):

1. **Business type** (`business_type` setting): picks default modules, default order types and the wording. **Locked after the first order.**
2. **Modules** (`enabled_modules` setting, JSON list): large feature areas with screens, routes, permissions and listeners.
3. **Optional features** (`feature_*_enabled` settings): smaller paid features that work with any business type.

## Business types

`App\Modules\BusinessType`:

| Value | Label | Default modules | Default active order types |
|---|---|---|---|
| `restaurant` | مطعم | Restaurant, Delivery, Manufacturing | dine_in, takeaway, delivery, companies, talabat |
| `cafe` | كافيه | Restaurant | dine_in, takeaway, companies, instant_sale |
| `bakery` | مخبز / حلواني | Restaurant, Manufacturing, Retail | takeaway, companies, instant_sale |
| `fashion` | ملابس وأحذية | Retail | companies, instant_sale |
| `grocery` | سوبر ماركت / بقالة | Retail, Delivery | delivery, companies, instant_sale |
| `general_retail` | تجزئة عامة | Retail | companies, instant_sale |

Web orders and External sellers are off by default for every type; the vendor switches them on.

Setting the type: the first-run **Setup wizard** (`/admin`, «معالج الإعداد», vendor only, shown until setup is done) or `php artisan pos:profile <type>`. Both refuse once any order exists (`BusinessTypeLockedException`).

## Modules

`App\Modules\Module`:

| Module | Value | What it owns |
|---|---|---|
| Restaurant | `restaurant` | Table types, floor designer, kitchen stations, kitchen tickets and display, service charge, kitchen performance report. |
| Delivery | `delivery` | Drivers, regions and delivery fees, driver performance report. |
| Web orders | `web_orders` | Website order screen, place-order API, web orders report. |
| Manufacturing | `manufacturing` | Manufacturing recipes and orders, yield report. |
| External sellers | `external_sellers` | Partner stores, their orders and settlements. Stored as the `feature_outsourced_orders_enabled` flag. |
| Retail | `retail` | Barcode-first selling and product variants. |

### How a module is wired

Each module has a provider extending `App\Modules\ModuleServiceProvider`:

- `bootModule()` runs only when the module is on: listeners, behaviours, Filament screens.
- `bootAlways()` runs regardless, for wiring that follows existing data (for example money owed to an external seller after the module is switched off).
- Listeners also call `ModuleServiceProvider::enabledNow($module)` at handle time, so a switch takes effect without restarting queue workers.

### What "off" means

| Surface | Behaviour when the module is off |
|---|---|
| Cashier routes | `module:<name>` middleware answers 404. |
| Admin screens | Listed in `ModuleScreens::map()`; hidden from navigation and answer 404. Resources use the `BelongsToModule` trait. |
| Permissions | `Module::permissions()` are denied by `Gate::before` for everyone, admin and vendor included, and hidden on the Roles screen. They stay on roles, so switching back restores them. |
| Order types | `OrderTypeCatalog::available()/active()/options()` hide types whose behaviour belongs to an off module. `all()` still resolves old orders. |
| Listeners | Return early via `enabledNow()`. |

Disabling a module or feature can be blocked while it still has open data, through `FeatureService::registerModuleDisableBlocker()` (for example `CustomFeesDisableBlocker`, `EquipmentDisableBlocker`, `SellersDisableBlocker`, `MultiTerminalDisableBlocker`, `DeferredPaymentDisableBlocker`, and `ModuleOpenOrdersBlocker`, which refuses turning off the restaurant, delivery or web orders module while orders of its types are open).

## Optional features

`App\Enums\OptionalFeature`, toggled in the vendor console:

| Feature | Setting | Adds |
|---|---|---|
| Revenues | `feature_revenues_enabled` | Revenue types, other revenues on shifts, revenues in the expenses report. |
| Custom fees | `feature_custom_fees_enabled` | Free-text fees on orders and a fees report. |
| Equipment | `feature_equipment_enabled` | Equipment catalogue, equipment on orders, equipment report. |
| Variants | `feature_variants_enabled` | Product groups (models) with sizes, colours and other axes. |
| Outsourced orders | `feature_outsourced_orders_enabled` | The External sellers module switch. |
| Multiple sales points («منافذ البيع») | `feature_multi_terminal_enabled` | Multiple drawer sessions per shift, device binding, per-sales-point receipt printer (server or Turbo Label), handovers, per-sales-point expenses and report tabs. `max_terminals` caps active points. Can't be switched off during a shift that used drawers. |
| Sales point separation | `feature_terminal_separation_enabled` | Needs multi-terminal. Each sales point works like a branch: own issue warehouse, owns its orders; web orders go to a chosen sales point. See [money](money.md#sales-point-separation-فصل-منافذ-البيع). |
| Deferred payment («الدفع الآجل») | `feature_deferred_payment_enabled` | Partial / pay-later completion, the receivables tab, companies accounts page, deferred cards and tables in reports. Blocked from switching off while customers still owe money. |
| Sales returns | `feature_returns_enabled` | POS returns and exchanges, the order returns list, returns report, returns cards/columns, the receipt order-number barcode. Purchase returns are not affected. |
| Closed-shift edits | `feature_closed_shift_edits_enabled` | Gates `shift_money.edit_closed`: add/edit/delete money rows of a closed shift on the shift money page. Off → closed shifts are read-only for everyone, the vendor included. |
| Purchase barcodes | `feature_purchase_barcodes_enabled` | «توليد باركود للأصناف» on purchase invoices: sequential codes from `purchase_barcode_prefix` / `_digits` / `_next_number`. |
| Label printing | `feature_label_printing_enabled` | «تصميمات الملصقات», «طباعة ملصقات» actions, `print_labels`. See [Printing](/dev/printing). |
| Document barcodes | `feature_document_barcodes_enabled` | Barcode column on purchase invoices, purchase returns, stock issues, stock transfers and waste; label printing from the first four. |
| AI assistant («المساعد الذكي») | `feature_ai_assistant_enabled` | The remote MCP server at `/mcp/pos` and its admin pages, `use_ai_assistant`. See [AI assistant](/dev/ai-mcp). |
| AI direct queries | `feature_ai_queries_enabled` | `run_readonly_query` + `describe_data_model` MCP tools on `ai_*` views, admins and vendor only. |

Every feature is **off on a fresh install**. The upgrade migrations that introduced deferred payment, returns and closed-shift edits switched them on only for installs that already used them. While a feature is off its screens answer 404 (`ModuleScreens`), its permissions are denied for everyone and hidden on the Roles screen, and its parts disappear from the POS, reports, exports and receipts. Recorded data is never deleted and still counts in totals.

The cashier reads features through the Inertia shared prop `features` (for example `features.variants`, `features.multi_terminal`).

## Wording

Retail installs must not show restaurant words («طاولة», «مطبخ», «مطعم»…). Use `App\Modules\Wording::text('<restaurant text>')` in PHP; retail overrides live in `lang/profiles/retail/ar.json`.

::: warning Never use `__()` for Arabic UI strings
`lang/ar.json` maps Arabic keys to English. `__()` on an Arabic string can show English.
:::

Two tests guard this: `tests/Feature/Modules/ShopAdminWordingTest.php` opens every admin screen as fashion, grocery and general retail, and `PosRestaurantWordsTest.php` scans `resources/js` for restaurant words. New restaurant words in shared UI fail CI.

## Retail

### Instant sale

A normal order-type row (`instant_sale`, «بيع مباشر», behaviour `instant`) whose tab body is a scan-and-pay screen. Active by default for non-restaurant types; restaurants can activate it.

- `POST /pos/instant-sales` (`InstantSaleService::sell`) creates, pays and completes an order in **one transaction**. Idempotent via `orders.client_uuid`. The order number is assigned at pay time.
- It dispatches no `OrderItemsChanged`, so no kitchen ticket is made.
- Held carts: `held_sales` table and `/pos/held-sales`; deleted when the shift ends.
- Returns and exchanges: `/pos/instant-returns/lookup` and `/pos/instant-returns`.
- Product lookup: `GET /products/lookup` (`ProductLookupService`) resolves barcodes, multi-barcodes and scale barcodes; inactive SKUs are skipped.
- Gate: tab permission `pos.tab.type.instant_sale` + `order.complete`.

### Variants

Behind `OptionalFeature::VARIANTS`.

| Table | Purpose |
|---|---|
| `product_groups` | The model: `sku_prefix`, `base_price`, `base_cost`, `unit`, `is_active`. |
| `product_options` / `product_option_values` | Axes (size, colour, fit…) and their values (`code`, `color_hex`). Any number of axes. |
| `product_variant_values` | Pivot linking each SKU to one value per axis. |
| `products` | Each SKU is a product with `product_group_id`, unique `sku`, `variant_label`, `has_custom_price`, `is_active`. |

Rules:

- `products.price` always holds the effective price. SKUs with `has_custom_price = false` follow the group `base_price` (synced by `ProductVariantService::syncGroupFields` when the group is saved). `base_cost` only seeds new SKUs.
- `ProductVariantService::generate()` builds the cartesian product of the chosen values, at most 500 combinations per run, and makes EAN-13 barcodes that avoid the scale prefix.
- Weighted units (kg, gram) cannot be variants.
- Cashier: group tile opens `VariantPickerModal`; `PosVariantCatalogService` supplies the matrix.
