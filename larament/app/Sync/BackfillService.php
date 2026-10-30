<?php

namespace App\Sync;

use App\Models\PlatformConnection;
use App\Sync\Exceptions\StaleLockException;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class BackfillService
{
    /**
     * Queue all catalog documents (products, recipes, categories, suppliers, expense types, staff)
     * with priority 1, skipping those already pending.
     */
    public function queueCatalog(?PlatformConnection $connection = null): int
    {
        $connection = $connection ?? PlatformConnection::active();
        if (! $connection) {
            return 0;
        }

        $now = now();
        $total = 0;

        DB::transaction(function () use ($now, &$total) {
            // Products
            $total += DB::affectingStatement(
                "INSERT INTO sync_outbox (doc_type, doc_uuid, doc_key, op, priority, occurred_at)
                SELECT 'product', p.uuid, NULL, 'upsert', 1, ?
                FROM products p
                WHERE p.uuid IS NOT NULL
                  AND NOT EXISTS (
                    SELECT 1 FROM sync_outbox o
                    WHERE o.doc_type = 'product'
                      AND o.doc_uuid = p.uuid
                      AND o.sent_at IS NULL
                      AND o.failed_at IS NULL
                  )",
                [$now]
            );

            // Recipes (one per product that has components)
            $total += DB::affectingStatement(
                "INSERT INTO sync_outbox (doc_type, doc_uuid, doc_key, op, priority, occurred_at)
                SELECT 'recipe', p.uuid, NULL, 'upsert', 1, ?
                FROM products p
                WHERE p.uuid IS NOT NULL
                  AND EXISTS (
                    SELECT 1 FROM product_components pc WHERE pc.product_id = p.id
                  )
                  AND NOT EXISTS (
                    SELECT 1 FROM sync_outbox o
                    WHERE o.doc_type = 'recipe'
                      AND o.doc_uuid = p.uuid
                      AND o.sent_at IS NULL
                      AND o.failed_at IS NULL
                  )",
                [$now]
            );

            // Categories
            $total += DB::affectingStatement(
                "INSERT INTO sync_outbox (doc_type, doc_uuid, doc_key, op, priority, occurred_at)
                SELECT 'category', c.uuid, NULL, 'upsert', 1, ?
                FROM categories c
                WHERE c.uuid IS NOT NULL
                  AND NOT EXISTS (
                    SELECT 1 FROM sync_outbox o
                    WHERE o.doc_type = 'category'
                      AND o.doc_uuid = c.uuid
                      AND o.sent_at IS NULL
                      AND o.failed_at IS NULL
                  )",
                [$now]
            );

            // Suppliers
            $total += DB::affectingStatement(
                "INSERT INTO sync_outbox (doc_type, doc_uuid, doc_key, op, priority, occurred_at)
                SELECT 'supplier', s.uuid, NULL, 'upsert', 1, ?
                FROM suppliers s
                WHERE s.uuid IS NOT NULL
                  AND NOT EXISTS (
                    SELECT 1 FROM sync_outbox o
                    WHERE o.doc_type = 'supplier'
                      AND o.doc_uuid = s.uuid
                      AND o.sent_at IS NULL
                      AND o.failed_at IS NULL
                  )",
                [$now]
            );

            // Expense Types
            $total += DB::affectingStatement(
                "INSERT INTO sync_outbox (doc_type, doc_uuid, doc_key, op, priority, occurred_at)
                SELECT 'expense_type', et.uuid, NULL, 'upsert', 1, ?
                FROM expence_types et
                WHERE et.uuid IS NOT NULL
                  AND NOT EXISTS (
                    SELECT 1 FROM sync_outbox o
                    WHERE o.doc_type = 'expense_type'
                      AND o.doc_uuid = et.uuid
                      AND o.sent_at IS NULL
                      AND o.failed_at IS NULL
                  )",
                [$now]
            );

            // Staff (Users)
            $total += DB::affectingStatement(
                "INSERT INTO sync_outbox (doc_type, doc_uuid, doc_key, op, priority, occurred_at)
                SELECT 'staff', u.uuid, NULL, 'upsert', 1, ?
                FROM users u
                WHERE u.uuid IS NOT NULL
                  AND NOT EXISTS (
                    SELECT 1 FROM sync_outbox o
                    WHERE o.doc_type = 'staff'
                      AND o.doc_uuid = u.uuid
                      AND o.sent_at IS NULL
                      AND o.failed_at IS NULL
                  )",
                [$now]
            );
        });

        return $total;
    }

    /**
     * Queue history documents for the specified business date range with priority 1,
     * skipping documents already pending.
     */
    public function queueRange(CarbonInterface $from, CarbonInterface $to, ?PlatformConnection $connection = null): int
    {
        $connection = $connection ?? PlatformConnection::active();
        if (! $connection) {
            return 0;
        }

        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        if ($fromDate > $toDate) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        $now = now();
        $total = 0;

        DB::transaction(function () use ($connection, $fromDate, $toDate, $now, &$total) {
            // Shifts
            $total += DB::affectingStatement(
                "INSERT INTO sync_outbox (doc_type, doc_uuid, doc_key, op, priority, occurred_at)
                SELECT 'shift', s.uuid, NULL, 'upsert', 1, ?
                FROM shifts s
                WHERE s.uuid IS NOT NULL
                  AND s.business_date >= ? AND s.business_date <= ?
                  AND NOT EXISTS (
                    SELECT 1 FROM sync_outbox o
                    WHERE o.doc_type = 'shift'
                      AND o.doc_uuid = s.uuid
                      AND o.sent_at IS NULL
                      AND o.failed_at IS NULL
                  )",
                [$now, $fromDate, $toDate]
            );

            // Final orders (completed/cancelled)
            $total += DB::affectingStatement(
                "INSERT INTO sync_outbox (doc_type, doc_uuid, doc_key, op, priority, occurred_at)
                SELECT 'order', o.uuid, NULL, 'upsert', 1, ?
                FROM orders o
                JOIN shifts s ON o.shift_id = s.id
                WHERE o.uuid IS NOT NULL
                  AND o.status IN ('completed', 'cancelled')
                  AND s.business_date >= ? AND s.business_date <= ?
                  AND NOT EXISTS (
                    SELECT 1 FROM sync_outbox so
                    WHERE so.doc_type = 'order'
                      AND so.doc_uuid = o.uuid
                      AND so.sent_at IS NULL
                      AND so.failed_at IS NULL
                  )",
                [$now, $fromDate, $toDate]
            );

            // Order returns of those shifts
            $total += DB::affectingStatement(
                "INSERT INTO sync_outbox (doc_type, doc_uuid, doc_key, op, priority, occurred_at)
                SELECT 'order_return', r.uuid, NULL, 'upsert', 1, ?
                FROM order_returns r
                JOIN shifts s ON r.shift_id = s.id
                WHERE r.uuid IS NOT NULL
                  AND s.business_date >= ? AND s.business_date <= ?
                  AND NOT EXISTS (
                    SELECT 1 FROM sync_outbox so
                    WHERE so.doc_type = 'order_return'
                      AND so.doc_uuid = r.uuid
                      AND so.sent_at IS NULL
                      AND so.failed_at IS NULL
                  )",
                [$now, $fromDate, $toDate]
            );

            // Expenses of those shifts
            $total += DB::affectingStatement(
                "INSERT INTO sync_outbox (doc_type, doc_uuid, doc_key, op, priority, occurred_at)
                SELECT 'expense', e.uuid, NULL, 'upsert', 1, ?
                FROM expenses e
                JOIN shifts s ON e.shift_id = s.id
                WHERE e.uuid IS NOT NULL
                  AND s.business_date >= ? AND s.business_date <= ?
                  AND NOT EXISTS (
                    SELECT 1 FROM sync_outbox so
                    WHERE so.doc_type = 'expense'
                      AND so.doc_uuid = e.uuid
                      AND so.sent_at IS NULL
                      AND so.failed_at IS NULL
                  )",
                [$now, $fromDate, $toDate]
            );

            // Closed purchase invoices
            $total += DB::affectingStatement(
                "INSERT INTO sync_outbox (doc_type, doc_uuid, doc_key, op, priority, occurred_at)
                SELECT 'purchase_invoice', pi.uuid, NULL, 'upsert', 1, ?
                FROM purchase_invoices pi
                WHERE pi.uuid IS NOT NULL
                  AND pi.closed_at IS NOT NULL
                  AND pi.business_date >= ? AND pi.business_date <= ?
                  AND NOT EXISTS (
                    SELECT 1 FROM sync_outbox so
                    WHERE so.doc_type = 'purchase_invoice'
                      AND so.doc_uuid = pi.uuid
                      AND so.sent_at IS NULL
                      AND so.failed_at IS NULL
                  )",
                [$now, $fromDate, $toDate]
            );

            // Closed purchase returns
            $total += DB::affectingStatement(
                "INSERT INTO sync_outbox (doc_type, doc_uuid, doc_key, op, priority, occurred_at)
                SELECT 'purchase_return', rpi.uuid, NULL, 'upsert', 1, ?
                FROM return_purchase_invoices rpi
                WHERE rpi.uuid IS NOT NULL
                  AND rpi.closed_at IS NOT NULL
                  AND rpi.business_date >= ? AND rpi.business_date <= ?
                  AND NOT EXISTS (
                    SELECT 1 FROM sync_outbox so
                    WHERE so.doc_type = 'purchase_return'
                      AND so.doc_uuid = rpi.uuid
                      AND so.sent_at IS NULL
                      AND so.failed_at IS NULL
                  )",
                [$now, $fromDate, $toDate]
            );

            // Closed wastes
            $total += DB::affectingStatement(
                "INSERT INTO sync_outbox (doc_type, doc_uuid, doc_key, op, priority, occurred_at)
                SELECT 'waste', w.uuid, NULL, 'upsert', 1, ?
                FROM wastes w
                WHERE w.uuid IS NOT NULL
                  AND w.closed_at IS NOT NULL
                  AND w.business_date >= ? AND w.business_date <= ?
                  AND NOT EXISTS (
                    SELECT 1 FROM sync_outbox so
                    WHERE so.doc_type = 'waste'
                      AND so.doc_uuid = w.uuid
                      AND so.sent_at IS NULL
                      AND so.failed_at IS NULL
                  )",
                [$now, $fromDate, $toDate]
            );

            // Closed stocktakings
            $total += DB::affectingStatement(
                "INSERT INTO sync_outbox (doc_type, doc_uuid, doc_key, op, priority, occurred_at)
                SELECT 'stocktaking', st.uuid, NULL, 'upsert', 1, ?
                FROM stocktakings st
                WHERE st.uuid IS NOT NULL
                  AND st.closed_at IS NOT NULL
                  AND st.business_date >= ? AND st.business_date <= ?
                  AND NOT EXISTS (
                    SELECT 1 FROM sync_outbox so
                    WHERE so.doc_type = 'stocktaking'
                      AND so.doc_uuid = st.uuid
                      AND so.sent_at IS NULL
                      AND so.failed_at IS NULL
                  )",
                [$now, $fromDate, $toDate]
            );

            // Closed days: inventory_day and day_summary for every CLOSED day in range
            $closedDates = DB::table('inventory_item_movement_daily')
                ->whereNotNull('closed_at')
                ->whereBetween('date', [$fromDate, $toDate])
                ->distinct()
                ->pluck('date');

            foreach ($closedDates as $rawDate) {
                $dateStr = Carbon::parse($rawDate)->toDateString();

                // InventoryDay
                $invUuid = DateDocument::uuid(DocumentType::InventoryDay, $dateStr, $connection->branch_uuid);
                $invPending = DB::table('sync_outbox')
                    ->where('doc_type', DocumentType::InventoryDay->value)
                    ->where('doc_uuid', $invUuid)
                    ->whereNull('sent_at')
                    ->whereNull('failed_at')
                    ->exists();

                if (! $invPending) {
                    DB::table('sync_outbox')->insert([
                        'doc_type' => DocumentType::InventoryDay->value,
                        'doc_uuid' => $invUuid,
                        'doc_key' => $dateStr,
                        'op' => 'upsert',
                        'priority' => 1,
                        'occurred_at' => $now,
                    ]);
                    $total++;
                }

                // DaySummary
                $summaryUuid = DateDocument::uuid(DocumentType::DaySummary, $dateStr, $connection->branch_uuid);
                $summaryPending = DB::table('sync_outbox')
                    ->where('doc_type', DocumentType::DaySummary->value)
                    ->where('doc_uuid', $summaryUuid)
                    ->whereNull('sent_at')
                    ->whereNull('failed_at')
                    ->exists();

                if (! $summaryPending) {
                    DB::table('sync_outbox')->insert([
                        'doc_type' => DocumentType::DaySummary->value,
                        'doc_uuid' => $summaryUuid,
                        'doc_key' => $dateStr,
                        'op' => 'upsert',
                        'priority' => 1,
                        'occurred_at' => $now,
                    ]);
                    $total++;
                }
            }
        });

        return $total;
    }

    /**
     * Default lock expiration timeout in seconds (15 minutes).
     */
    public const DEFAULT_LOCK_TIMEOUT_SECONDS = 900;

    /**
     * Explicitly recover incomplete or failed initial backfill if needed.
     */
    public function recoverInitialBackfillIfNeeded(?PlatformConnection $connection = null, int $lockTimeoutSeconds = self::DEFAULT_LOCK_TIMEOUT_SECONDS): bool
    {
        $connection = $connection ?? PlatformConnection::active();
        if (! $connection) {
            return false;
        }

        return $this->processInitialBackfill($connection, $lockTimeoutSeconds);
    }

    /**
     * Atomically claim the initial backfill lock for this connection.
     * Compatible with both SQLite and MySQL using DB transactions and row locking.
     * Returns the lock owner UUID on success, or null on failure.
     */
    public function claimBackfillLock(PlatformConnection $connection, int $lockTimeoutSeconds = self::DEFAULT_LOCK_TIMEOUT_SECONDS): ?string
    {
        $timeout = $lockTimeoutSeconds > 0 ? $lockTimeoutSeconds : self::DEFAULT_LOCK_TIMEOUT_SECONDS;
        $owner = (string) Str::uuid();
        $now = now();

        return DB::transaction(function () use ($connection, $owner, $now, $timeout) {
            /** @var PlatformConnection|null $record */
            $record = PlatformConnection::where('id', $connection->id)->lockForUpdate()->first();

            if (! $record || $record->status !== 'active') {
                return null;
            }

            if ($record->backfill_status === 'completed') {
                return null;
            }

            if ($record->backfill_status === 'failed') {
                $nextAttempt = $record->backfill_next_attempt_at ? Carbon::parse($record->backfill_next_attempt_at) : null;
                if ($nextAttempt && $nextAttempt->isFuture()) {
                    return null;
                }
            } elseif ($record->backfill_status === 'in_progress') {
                $lockedAt = $record->backfill_locked_at ? Carbon::parse($record->backfill_locked_at) : null;
                $isExpired = $lockedAt === null || $lockedAt->diffInSeconds($now) >= $timeout;

                if (! $isExpired) {
                    return null;
                }

                Log::warning('Recovering abandoned or crashed initial backfill lock', [
                    'connection_id' => $record->id,
                    'abandoned_owner' => $record->backfill_lock_owner,
                    'locked_at' => $record->backfill_locked_at,
                    'elapsed_seconds' => $lockedAt ? $lockedAt->diffInSeconds($now) : null,
                ]);
            }

            $record->update([
                'backfill_status' => 'in_progress',
                'backfill_lock_owner' => $owner,
                'backfill_locked_at' => $now,
                'last_error' => null,
            ]);

            return $owner;
        });
    }

    /**
     * Touch/refresh the lock heartbeat to maintain ownership during long operations.
     */
    public function touchLock(PlatformConnection $connection, string $owner): bool
    {
        $updated = PlatformConnection::where('id', $connection->id)
            ->where('backfill_lock_owner', $owner)
            ->where('status', 'active')
            ->where('backfill_status', 'in_progress')
            ->update([
                'backfill_locked_at' => now(),
            ]);

        return $updated > 0;
    }

    /**
     * Validate that the active status, lock owner, and lease remain valid within a step transaction.
     * Throws StaleLockException if the claimant is stale, superseded, or connection inactive.
     *
     * @throws StaleLockException
     */
    protected function validateLockOwnershipAndActive(?PlatformConnection $record, string $owner, int $lockTimeoutSeconds): void
    {
        if (! $record || $record->status !== 'active') {
            throw new StaleLockException('Platform connection is not active.');
        }

        if ($record->backfill_lock_owner !== $owner) {
            throw new StaleLockException('Lost backfill lock ownership: claimant is stale.');
        }

        if ($record->backfill_status !== 'in_progress') {
            throw new StaleLockException('Backfill status is not in_progress.');
        }

        $lockedAt = $record->backfill_locked_at ? Carbon::parse($record->backfill_locked_at) : null;
        if ($lockedAt === null || $lockedAt->diffInSeconds(now()) >= $lockTimeoutSeconds) {
            throw new StaleLockException('Backfill lock lease has expired: claimant is stale.');
        }
    }

    /**
     * Execute catalog backfill step under row lock in an atomic transaction.
     *
     * @throws StaleLockException
     */
    public function executeCatalogStep(
        PlatformConnection $connection,
        string $owner,
        int $lockTimeoutSeconds = self::DEFAULT_LOCK_TIMEOUT_SECONDS
    ): bool {
        return DB::transaction(function () use ($connection, $owner, $lockTimeoutSeconds) {
            /** @var PlatformConnection|null $record */
            $record = PlatformConnection::where('id', $connection->id)->lockForUpdate()->first();

            $this->validateLockOwnershipAndActive($record, $owner, $lockTimeoutSeconds);

            if ($record->backfill_catalog_queued) {
                return true;
            }

            $this->queueCatalog($record);

            $isCompleted = (bool) $record->backfill_range_queued;

            $record->update([
                'backfill_catalog_queued' => true,
                'backfill_status' => $isCompleted ? 'completed' : 'in_progress',
                'backfill_completed_at' => $isCompleted ? now() : null,
                'backfill_lock_owner' => $isCompleted ? null : $owner,
                'backfill_locked_at' => $isCompleted ? null : now(),
                'backfill_next_attempt_at' => null,
                'last_error' => null,
            ]);

            return true;
        });
    }

    /**
     * Execute range backfill step under row lock in an atomic transaction.
     *
     * @throws StaleLockException
     */
    public function executeRangeStep(
        PlatformConnection $connection,
        string $owner,
        CarbonInterface $from,
        CarbonInterface $to,
        int $lockTimeoutSeconds = self::DEFAULT_LOCK_TIMEOUT_SECONDS
    ): bool {
        return DB::transaction(function () use ($connection, $owner, $from, $to, $lockTimeoutSeconds) {
            /** @var PlatformConnection|null $record */
            $record = PlatformConnection::where('id', $connection->id)->lockForUpdate()->first();

            $this->validateLockOwnershipAndActive($record, $owner, $lockTimeoutSeconds);

            if ($record->backfill_range_queued) {
                return true;
            }

            $this->queueRange($from, $to, $record);

            $isCompleted = (bool) $record->backfill_catalog_queued;

            $record->update([
                'backfill_range_queued' => true,
                'backfill_status' => $isCompleted ? 'completed' : 'in_progress',
                'backfill_completed_at' => $isCompleted ? now() : null,
                'backfill_lock_owner' => $isCompleted ? null : $owner,
                'backfill_locked_at' => $isCompleted ? null : now(),
                'backfill_next_attempt_at' => null,
                'last_error' => null,
            ]);

            return true;
        });
    }

    /**
     * Process initial backfill (catalog and 90-day history range) for the connection,
     * recording durable progress and handling failures gracefully with proper owner locking.
     *
     * For each backfill step (catalog and range), holds the DB connection row lock,
     * validates active status/current owner and checkpoint, and executes outbox writes
     * and its completion flag in the SAME database transaction; stale claimants are unable
     * to write or overwrite newer state.
     */
    public function processInitialBackfill(?PlatformConnection $connection = null, int $lockTimeoutSeconds = self::DEFAULT_LOCK_TIMEOUT_SECONDS): bool
    {
        $connection = $connection ?? PlatformConnection::active();
        if (! $connection) {
            return false;
        }

        $connection->refresh();

        if ($connection->backfill_status === 'completed') {
            return true;
        }

        $effectiveTimeout = $lockTimeoutSeconds > 0 ? $lockTimeoutSeconds : self::DEFAULT_LOCK_TIMEOUT_SECONDS;
        $owner = $this->claimBackfillLock($connection, $effectiveTimeout);
        if ($owner === null) {
            return false;
        }

        try {
            // Step 1: Catalog backfill (held under connection row lock in same transaction)
            if (! $connection->backfill_catalog_queued) {
                $this->executeCatalogStep($connection, $owner, $effectiveTimeout);
                $connection->refresh();
            }

            // Step 2: Range backfill (held under connection row lock in same transaction)
            if (! $connection->backfill_range_queued) {
                $from = $connection->backfill_range_from ? Carbon::parse((string) $connection->backfill_range_from) : Carbon::today()->subDays(90);
                $to = $connection->backfill_range_to ? Carbon::parse((string) $connection->backfill_range_to) : Carbon::today();

                $this->executeRangeStep($connection, $owner, $from, $to, $effectiveTimeout);
                $connection->refresh();
            }

            // Final safety checkpoint: ensure completed status is recorded if both steps succeeded
            if ($connection->backfill_status !== 'completed' && $connection->backfill_catalog_queued && $connection->backfill_range_queued) {
                DB::transaction(function () use ($connection, $owner, $effectiveTimeout) {
                    /** @var PlatformConnection|null $record */
                    $record = PlatformConnection::where('id', $connection->id)->lockForUpdate()->first();

                    $this->validateLockOwnershipAndActive($record, $owner, $effectiveTimeout);

                    $record->update([
                        'backfill_status' => 'completed',
                        'backfill_completed_at' => now(),
                        'backfill_next_attempt_at' => null,
                        'backfill_lock_owner' => null,
                        'backfill_locked_at' => null,
                        'last_error' => null,
                    ]);
                });
            }

            PlatformConnection::flushCache();

            return true;
        } catch (Throwable $e) {
            Log::error('Initial backfill failed: '.$e->getMessage(), [
                'connection_id' => $connection->id,
                'owner' => $owner,
                'exception' => $e,
            ]);

            // Only the active owner may update connection to failed.
            // A stale claimant whose lock was lost or expired must NOT overwrite newer state.
            if (! ($e instanceof StaleLockException)) {
                DB::transaction(function () use ($connection, $owner, $e) {
                    /** @var PlatformConnection|null $record */
                    $record = PlatformConnection::where('id', $connection->id)->lockForUpdate()->first();
                    if ($record && $record->status === 'active' && $record->backfill_lock_owner === $owner) {
                        $attempts = (int) $record->backfill_attempts + 1;
                        $backoff = BatchSender::backoffMinutes($attempts);

                        $record->update([
                            'backfill_status' => 'failed',
                            'backfill_attempts' => $attempts,
                            'backfill_next_attempt_at' => now()->addMinutes($backoff),
                            'backfill_lock_owner' => null,
                            'backfill_locked_at' => null,
                            'last_error' => 'Initial backfill failed: '.$e->getMessage(),
                        ]);
                    }
                });
            }

            PlatformConnection::flushCache();

            return false;
        }
    }
}
