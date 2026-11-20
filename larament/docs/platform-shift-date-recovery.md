# Historical shift dates after first platform sync

On MariaDB with implicit TIMESTAMP defaults enabled, the original non-null first
TIMESTAMP column (`shifts.start_at`) acquired DEFAULT CURRENT_TIMESTAMP and ON
UPDATE CURRENT_TIMESTAMP. Updating UUIDs/business dates could therefore overwrite
historical shift starts. The business-date backfill then copied the overwritten
date, and the connector correctly sent that incorrect stored date to the platform.

Migration `2026_10_31_235959_preserve_shift_start_at` defines an explicit default
without ON UPDATE. Its timestamp precedes the sync backfills so fresh installations
are protected before UUIDs and business dates are updated. Existing installations
also run it as a pending migration. It preserves existing values; it cannot recover
timestamps already overwritten. Rolling it back intentionally keeps the safe
definition.

Deploy and preview on the POS:

```bash
php artisan migrate --force
php artisan platform:repair-shift-dates
```

If historical `created_at` is the correct shift opening time, apply:

```bash
php artisan platform:repair-shift-dates --apply --from-created-at
php artisan platform:sync --max-seconds=120
```

For closed shifts, the repair selects start dates after their end date when the
historical creation timestamp is present and no later than the end. It also handles
open shifts when created_at is on an earlier date than start_at and historical
orders prove that the shift was already in use before its overwritten start date.
An open shift without that order evidence is preserved. Normal shifts,
valid after-midnight business dates and ambiguous records are preserved. It backs
up original shift records under the private local disk's `repairs/shift-dates/`
directory before changing anything. An audit-write failure aborts the repair.

Shift starts and business dates are restored from created_at in one transaction.
New outbox versions are queued for shifts and related orders, returns and expenses,
even if their old versions were already sent. Day summaries are regenerated for
both old and corrected dates. Existing outbox history is preserved. Repeat runs
are idempotent. An unpaired POS can be repaired before its first backfill.

Keep platform ingest/aggregate workers and its scheduler running. Higher document
versions update the existing warehouse rows and dirty both their previous and new
business dates, allowing incorrect old aggregates to be cleared without deleting
the branch or its history.

Validation: an isolated real MariaDB database reproduced the automatic overwrite;
the migration removed ON UPDATE and later UUID/date updates preserved start_at.
POS sync tests cover preview, explicit recovery choice, backups, requeueing,
idempotence and preserving valid dates.
