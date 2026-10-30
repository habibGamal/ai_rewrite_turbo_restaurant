<?php

namespace App\Sync;

use App\Models\PlatformConnection;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class BackfillService
{
    /**
     * Queue all catalog documents (products, recipes, categories, suppliers, expense types, staff)
     * with priority 1, skipping those already pending.
     */
    public function queueCatalog(): int
    {
        $connection = PlatformConnection::active();
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
    public function queueRange(CarbonInterface $from, CarbonInterface $to): int
    {
        $connection = PlatformConnection::active();
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
}
