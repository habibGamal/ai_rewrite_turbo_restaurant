# Vendor account & console

Every install has one hidden **vendor** account (the seller of the system, "god mode"). It is the only account that can choose the business type, modules and paid features.

## Create it or reset its password

```bash
php artisan pos:vendor
# Vendor account created.
# | Email             | Password         |
# | support@pos.local | 7hQk2...         |
# Store this password now; it is not shown again.

php artisan pos:vendor --email=you@example.com --password='a-strong-password'
```

- Default email `support@pos.local`; a random 16-character password is printed when `--password` is omitted (minimum 8 characters).
- Running it again resets the password (and restores the account if it was soft-deleted).
- The password is per install. Email reset is planned for later.

## How it stays hidden

| Mechanism | Effect |
|---|---|
| `users.is_vendor` + `HidesVendorScope` global scope | The vendor never appears in user lists, counts or selects. Primary-key lookups and eager `belongsTo` still resolve, so records the vendor created show the name «الدعم الفني». |
| `withVendor()` / `onlyVendor()` query macros | Explicit access when code needs it. |
| `vendor-aware` auth provider | Lets the vendor log in despite the scope. |
| `Gate::before` | `vendor.*` abilities are **only** for the vendor (admin does not get them). The vendor passes every other ability, except permissions of switched-off modules. |

## Vendor console

Filament page «لوحة الدعم الفني» at `/admin/vendor-console` (`vendor.console`), group «إدارة النظام». Sections:

1. **نوع النشاط**: the business type (read-only once an order exists).
2. **الوحدات**: module switches. Some refuse to turn off while they still have open data.
3. **المميزات**: optional features (revenues, custom fees, equipment, variants, outsourced orders, multi-terminal drawers). Multi-terminal also sets `max_terminals` (1-50) and registers a disable blocker refusing to switch off while any drawer has an open session. With multi-terminal on, the vendor can also turn on **sales point separation** and pick the sales point web orders belong to (`web_orders_terminal_id`).
4. **Change log**: every switch with time, old and new value and the user (stored in `feature_flag_audits`).

Vendor-owned settings (`SettingKey::isVendorOwned()`) are not shown, saved or reset by the shop's Settings page, and the `settings` artisan command refuses them.

## First-run setup wizard

«معالج الإعداد» (`SetupWizard`) shows for the vendor until setup is completed. Steps: **النشاط** (business type), **الوحدات** (modules), **بيانات المتجر** (shop name and details). It applies the type's defaults (active order types, a table type for restaurants) and sets `setup_completed`.

The CLI equivalent:

```bash
php artisan pos:profile                 # show type and modules
php artisan pos:profile fashion         # set type, reset modules to its defaults
php artisan pos:profile cafe --keep-modules
```

## Typical install checklist

1. Deploy and migrate ([Deployment](/technical/deployment)).
2. `php artisan pos:vendor` and keep the password.
3. Log in as the vendor, run the setup wizard.
4. Turn on extra modules or features the customer paid for.
5. Change the seeded admin password and create the shop's users.
6. If the shop has a website: set the [API key and integration token](/technical/website-integration#setup).
