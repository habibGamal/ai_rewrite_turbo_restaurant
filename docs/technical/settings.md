# Settings reference

Settings live in the `settings` table, read through `SettingsService` / the `setting()` helper with an in-memory cache. Keys are the `App\Enums\SettingKey` enum, which also holds each key's Arabic label, help text, default and validation rules.

Admin page: «الإعدادات» (`/admin/settings`, `manage_settings`). CLI: `php artisan settings get|set|reset ...`.

## Shop settings (Settings page)

### الإعدادات العامة

| Key | Label | Default | Notes |
|---|---|---|---|
| `website_url` | رابط الموقع | `http://127.0.0.1:38794` | Base URL of the linked website. Status callbacks go to `{website_url}/api/order-status`. |
| `cashier_printer_ip` | عنوان IP لطابعة الكاشير | `192.168.1.100` | Receipt printer. |
| `dine_in_service_charge` | رسوم الخدمة للطعام الداخلي | `0.12` | Fraction (0.12 = 12%). Items only. |
| `scale_barcode_prefix` | بادئة باركود الميزان | `23` | Prefix of price/weight-embedded scale barcodes. |
| `returns_max_days` | أقصى عدد أيام للمرتجع | `0` | 0 = no limit. Older returns need `order.return_any_age`. |
| `receipt_footer` | تذييل الفاتورة | Thank-you text | Printed at the end of receipts. |

### إعدادات المطعم / المتجر

`restaurant_name`, `restaurant_print_logo`, `restaurant_official_logo`, `restaurant_qr_logo`. The tab title reads «إعدادات المتجر» on retail installs.

### إدارة الفروع

| Key | Label | Notes |
|---|---|---|
| `node_type` | نوع النقطة الحالية | `independent` (مستقلة), `master` (رئيسية) or `slave` (فرع). See [Branches](/technical/branches). |
| `master_node_link` | رابط النقطة الرئيسية | Only for `slave`. |
| `web_api_key` | مفتاح API للموقع والفروع | Section «مفتاح الربط (API)». The `X-Api-Key` every caller must send. «توليد مفتاح» makes a 48-char hex key and saves it. `.env` `WEB_API_KEY` is only a fallback. Kept on reset-to-defaults. |
| `website_integration_token` | رمز التكامل من الموقع | Same section. Copied from the website admin; sent as `Authorization: Bearer` on status callbacks. Kept on reset-to-defaults. |

### إدارة الورديات

`allow_web_orders_shift_transfer` (نقل طلبات الويب المفتوحة إلى الوردية التالية): carry open web orders into the next shift instead of blocking shift end.

### تبويبات الكاشير

`cashier_tabs` (order, visibility of tabs) and `cashier_tabs_orientation` (`horizontal` / vertical).

### إدارة المشتريات

`costing_method`: `off` (إيقاف), `immediate` (فوري، آخر سعر), `weighted_average` (متوسط مرجح).

### شاشة المطبخ (KDS)

| Key | Default |
|---|---|
| `kds_item_tracking` | `true` |
| `kds_default_prep_minutes` | `10` |
| `kds_order_type_targets` | `{}` (minutes per order type) |
| `kds_warning_percent` | `75` |
| `kds_auto_finish_on_complete` | `true` |
| `kds_cashier_status_enabled` | `true` |
| `kds_recall_minutes` | `15` |
| `kds_new_order_sound` / `kds_change_sound` / `kds_cancel_sound` | `chime` / `bell` / `alert` |
| `kds_sound_volume` | `80` |
| `kds_reminder_seconds` | `30` (0 = off) |

### التشخيص والصيانة

Tools such as the cyclic-dependency check for manufactured product components.

## Vendor-owned settings

Not editable from the Settings page; set from the [vendor console](/technical/vendor).

| Key | Purpose |
|---|---|
| `business_type` | Business profile (locked after first order). |
| `enabled_modules` | JSON list of modules. |
| `setup_completed` | First-run wizard done. |
| `feature_revenues_enabled`, `feature_custom_fees_enabled`, `feature_equipment_enabled`, `feature_variants_enabled`, `feature_outsourced_orders_enabled` | Optional features. |

## Environment (`.env`)

| Variable | Purpose |
|---|---|
| `APP_LOCALE=ar`, `APP_TIMEZONE=Africa/Cairo` | Arabic UI, Cairo time. |
| `DB_*` | MySQL connection. |
| `QUEUE_CONNECTION=database` | Print jobs. |
| `BROADCAST_CONNECTION=reverb`, `REVERB_*`, `VITE_REVERB_*` | Real time. See [Real-time](/technical/realtime). |
| `WEB_API_KEY` | Fallback API key when the setting is empty. |
| `MANAGEMENT_SECRET_KEY` | `X-Management-Key` for `/api/management/*` (deploy, start, stop, status). |
| `OLD_DB_*` | Legacy database for `migrate:old-data`. |
