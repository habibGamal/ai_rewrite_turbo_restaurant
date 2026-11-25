# Coding Standards

Conventions observed in `AGENTS.md` and the `larament/` codebase:

- **Quality gate:** run `composer review` inside `larament/` before pushing (`./vendor/bin/pint` -> `./vendor/bin/pest --parallel` -> `./vendor/bin/phpstan analyse`).
- **Formatting:** Laravel Pint (`./vendor/bin/pint`).
- **Testing:** Pest PHP v4 (`./vendor/bin/pest --parallel`) with SQLite in-memory (`phpunit.xml`).
- **Static analysis:** PHPStan / Larastan level 5 (`./vendor/bin/phpstan analyse`), covering `app/`.
- **Filament v5 layout:** resources live under `app/Filament/Resources/` (with module resources under `app/Modules/<Module>/Filament/Resources/`); pages, widgets, and actions live in sibling directories.
- **Layering:** keep business logic in `app/Services/`, persistence helpers in `app/Repositories/`, typed payloads in `app/DTOs/`, and domain states/keys in `app/Enums/`.
- **Localization & UI:** Arabic UI (`APP_LOCALE=ar`) via `lang/ar.json`, `lang/profiles/retail/ar.json`, and `App\Modules\Wording::text()` (do not wrap Arabic strings in `__()`).
- **Locale defaults:** currency is `EGP` and timezone is `Africa/Cairo`.
- **Dev server rule:** never run `php artisan serve`; the owner manages dev servers.
