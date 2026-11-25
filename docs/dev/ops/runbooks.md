# Operations runbooks

Operational recovery and maintenance procedures for production POS installs.

## Historical shift date recovery

On MariaDB with implicit `TIMESTAMP` defaults enabled, the original `shifts.start_at` column could acquire `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`. Updating UUIDs or `business_date` on historical shifts then overwrote `start_at`, and the backfill copied that overwritten date to the platform. See [Platform sync](/dev/platform-sync) for how shift documents are synced.

Migration `2026_10_31_235959_preserve_shift_start_at` removes `ON UPDATE` while keeping existing values. Its rollback keeps the safe definition. To repair shifts whose `start_at` was already overwritten, preview first on the POS:

```bash
php artisan migrate --force
php artisan platform:repair-shift-dates
```

Verify from the table that historical `created_at` matches the real shift opening time, then apply and sync:

```bash
php artisan platform:repair-shift-dates --apply --from-created-at
php artisan platform:sync --max-seconds=120
```

How `platform:repair-shift-dates` (`RepairShiftDatesCommand`) works:

- **Candidate selection:** selects closed shifts where `DATE(start_at) > DATE(end_at)` and `created_at <= end_at`, plus open shifts where `DATE(created_at) < DATE(start_at)` and orders exist with `orders.created_at >= shifts.created_at` and `DATE(orders.created_at) < DATE(shifts.start_at)`. Normal shifts and valid post-midnight shifts are left untouched.
- **Backup before write:** saves the original shift rows under the `local` disk at `repairs/shift-dates/<uuid>.json` before modifying any row; aborts if writing the backup fails.
- **Transactional repair and requeue:** restores `start_at` and `business_date` from `created_at` in one transaction, queues new outbox rows for the shift and its orders, returns and expenses, and when paired queues `day_summary` for both the old and corrected dates. Repeat runs are idempotent.
- **Platform workers:** keep the platform ingest/aggregate workers and scheduler running so higher document versions update the warehouse rows and mark both old and corrected business dates dirty.
