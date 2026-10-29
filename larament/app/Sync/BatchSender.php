<?php

namespace App\Sync;

use App\Models\PlatformConnection;
use App\Models\SyncBatch;
use App\Models\SyncOutbox;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class BatchSender
{
    public function __construct(
        protected ?PlatformClient $client = null,
    ) {
        $this->client = $client ?? app(PlatformClient::class);
    }

    /**
     * Compute backoff duration in minutes based on attempt count.
     * Sequence: 1, 2, 5, 15, 30, 60 then stays at 60.
     */
    public static function backoffMinutes(int $attempts): int
    {
        return match ($attempts) {
            1 => 1,
            2 => 2,
            3 => 5,
            4 => 15,
            5 => 30,
            default => 60,
        };
    }

    /**
     * Run the sync loop sending pending batches and due outbox rows.
     */
    public function run(int $maxSeconds = 50): SyncRunResult
    {
        $connection = PlatformConnection::active();
        if (! $connection) {
            return new SyncRunResult;
        }

        $startTime = microtime(true);
        $batchesProcessed = 0;
        $totalSent = 0;
        $totalRejected = 0;
        $totalFailed = 0;
        $lastError = null;

        while ((microtime(true) - $startTime) < $maxSeconds) {
            // Step 1: Retry pending batch oldest first if one exists
            $batch = SyncBatch::where('status', 'pending')->orderBy('id', 'asc')->first();

            if ($batch) {
                /** @var Collection<int, SyncOutbox> $rows */
                $rows = SyncOutbox::where('batch_id', $batch->id)
                    ->whereNull('sent_at')
                    ->whereNull('failed_at')
                    ->orderBy('priority', 'desc')
                    ->orderBy('id', 'asc')
                    ->get();

                if ($rows->isEmpty()) {
                    $batch->update(['status' => 'failed']);

                    continue;
                }

                $isDue = $rows->contains(fn (SyncOutbox $row) => $row->next_attempt_at === null || $row->next_attempt_at <= now());
                if (! $isDue) {
                    // Pending batch is in backoff wait; preserve sequence and stop
                    break;
                }
            } else {
                // Step 2: Pick up to 500 due rows and assign to a new batch
                $batch = DB::transaction(function () {
                    /** @var Collection<int, SyncOutbox> $dueRows */
                    $dueRows = SyncOutbox::whereNull('sent_at')
                        ->whereNull('failed_at')
                        ->where(function ($q) {
                            $q->whereNull('next_attempt_at')
                                ->orWhere('next_attempt_at', '<=', now());
                        })
                        ->whereNull('batch_id')
                        ->orderBy('priority', 'desc')
                        ->orderBy('id', 'asc')
                        ->limit(500)
                        ->lockForUpdate()
                        ->get();

                    if ($dueRows->isEmpty()) {
                        return null;
                    }

                    $newBatch = SyncBatch::create([
                        'uuid' => (string) Str::uuid(),
                        'seq_from' => (int) $dueRows->min('id'),
                        'seq_to' => (int) $dueRows->max('id'),
                        'docs_count' => $dueRows->count(),
                        'status' => 'pending',
                    ]);

                    SyncOutbox::whereIn('id', $dueRows->pluck('id'))->update([
                        'batch_id' => $newBatch->id,
                    ]);

                    return $newBatch;
                });

                if (! $batch) {
                    // No due rows to send
                    break;
                }

                /** @var Collection<int, SyncOutbox> $rows */
                $rows = SyncOutbox::where('batch_id', $batch->id)
                    ->whereNull('sent_at')
                    ->whereNull('failed_at')
                    ->orderBy('priority', 'desc')
                    ->orderBy('id', 'asc')
                    ->get();
            }

            // Coalesce rows by (doc_type, doc_uuid)
            $grouped = $rows->groupBy(fn (SyncOutbox $row) => $row->doc_type.':'.$row->doc_uuid);
            $documents = [];

            foreach ($grouped as $group) {
                /** @var SyncOutbox $maxRow */
                $maxRow = $group->sortByDesc('id')->first();
                $version = (int) $maxRow->id;
                $op = (string) $maxRow->op;
                $docType = DocumentType::from($maxRow->doc_type);

                $builder = BuilderRegistry::for($docType);
                $builder->withKey($maxRow->doc_key);

                if ($op === 'delete') {
                    $data = null;
                } else {
                    $data = $builder->build($maxRow->doc_uuid);
                    if ($data === null) {
                        $op = 'delete';
                    }
                }

                $documents[] = [
                    'type' => $maxRow->doc_type,
                    'uuid' => $maxRow->doc_uuid,
                    'version' => $version,
                    'op' => $op,
                    'occurred_at' => Payload::ts($maxRow->occurred_at ?? now()),
                    'data' => $data,
                ];
            }

            $seqFrom = (int) $rows->min('id');
            $seqTo = (int) $rows->max('id');

            $envelope = [
                'schema_version' => 1,
                'batch_uuid' => $batch->uuid,
                'branch_uuid' => $connection->branch_uuid,
                'pos_version' => (string) config('app.version'),
                'sent_at' => Payload::ts(now()),
                'seq_from' => $seqFrom,
                'seq_to' => $seqTo,
                'documents' => $documents,
            ];

            try {
                $response = $this->client->sendBatch($connection, $envelope, $batch->uuid);
            } catch (Throwable $e) {
                $now = now();
                foreach ($rows as $row) {
                    $newAttempts = $row->attempts + 1;
                    $failedAt = $newAttempts >= 10 ? $now : null;
                    $nextAttempt = $now->copy()->addMinutes(self::backoffMinutes($newAttempts));

                    $row->update([
                        'attempts' => $newAttempts,
                        'last_error' => $e->getMessage(),
                        'next_attempt_at' => $nextAttempt,
                        'failed_at' => $failedAt,
                    ]);

                    if ($failedAt !== null) {
                        $totalFailed++;
                    }
                }

                $lastError = $e->getMessage();
                break;
            }

            $status = $response->status();

            // 200, 202, or 409
            if (in_array($status, [200, 202, 409], true)) {
                $now = now();
                $body = $response->json() ?? [];
                $acceptedUuids = $body['accepted'] ?? null;
                $rejectedList = $body['rejected'] ?? [];

                $rejectedMap = [];
                foreach ($rejectedList as $item) {
                    if (is_array($item) && isset($item['uuid'])) {
                        $rejectedMap[$item['uuid']] = $item['reason'] ?? 'Rejected by platform';
                    } elseif (is_string($item)) {
                        $rejectedMap[$item] = 'Rejected by platform';
                    }
                }

                if ($acceptedUuids !== null) {
                    $acceptedSet = array_flip($acceptedUuids);
                } else {
                    $acceptedSet = [];
                    foreach ($documents as $doc) {
                        if (! isset($rejectedMap[$doc['uuid']])) {
                            $acceptedSet[$doc['uuid']] = true;
                        }
                    }
                }

                // Mark accepted rows as sent
                $acceptedRows = $rows->filter(fn (SyncOutbox $row) => isset($acceptedSet[$row->doc_uuid]));
                if ($acceptedRows->isNotEmpty()) {
                    SyncOutbox::whereIn('id', $acceptedRows->pluck('id'))->update([
                        'sent_at' => $now,
                        'last_error' => null,
                    ]);
                }

                // Update rejected rows with backoff and release batch_id
                foreach ($rows as $row) {
                    if (isset($rejectedMap[$row->doc_uuid])) {
                        $reason = $rejectedMap[$row->doc_uuid];
                        $newAttempts = $row->attempts + 1;
                        $failedAt = $newAttempts >= 10 ? $now : null;
                        $nextAttempt = $now->copy()->addMinutes(self::backoffMinutes($newAttempts));

                        $row->update([
                            'attempts' => $newAttempts,
                            'last_error' => $reason,
                            'batch_id' => null,
                            'next_attempt_at' => $nextAttempt,
                            'failed_at' => $failedAt,
                        ]);

                        if ($failedAt !== null) {
                            $totalFailed++;
                        }
                    }
                }

                $hasRejections = ! empty($rejectedMap);
                $batch->update([
                    'status' => $hasRejections ? 'partial' : 'sent',
                    'response' => $body,
                ]);

                $connection->update([
                    'last_success_at' => $now,
                ]);

                $batchesProcessed++;
                $totalSent += count($acceptedSet);
                $totalRejected += count($rejectedMap);

                continue;
            }

            // 401
            if ($status === 401) {
                $connection->update([
                    'status' => 'revoked',
                    'last_error' => 'token rejected',
                ]);
                $lastError = 'token rejected';
                break;
            }

            // 413
            if ($status === 413) {
                if ($rows->count() <= 1) {
                    $now = now();
                    foreach ($rows as $row) {
                        $newAttempts = $row->attempts + 1;
                        $failedAt = $newAttempts >= 10 ? $now : null;
                        $nextAttempt = $now->copy()->addMinutes(self::backoffMinutes($newAttempts));

                        $row->update([
                            'attempts' => $newAttempts,
                            'last_error' => 'Payload too large (413)',
                            'batch_id' => null,
                            'next_attempt_at' => $nextAttempt,
                            'failed_at' => $failedAt,
                        ]);

                        if ($failedAt !== null) {
                            $totalFailed++;
                        }
                    }

                    $batch->update(['status' => 'failed']);
                    $lastError = 'Payload too large (413)';
                    break;
                }

                // Split: release the second half of the rows (batch_id = null), resend smaller batch
                $keepCount = (int) ceil($rows->count() / 2);
                $rowsToKeep = $rows->slice(0, $keepCount);
                $rowsToRelease = $rows->slice($keepCount);

                SyncOutbox::whereIn('id', $rowsToRelease->pluck('id'))->update(['batch_id' => null]);

                $batch->update([
                    'docs_count' => $rowsToKeep->count(),
                    'seq_from' => (int) $rowsToKeep->min('id'),
                    'seq_to' => (int) $rowsToKeep->max('id'),
                ]);

                continue;
            }

            // Other HTTP errors (500, 502, 503, 422, etc.)
            $now = now();
            $errorMessage = 'HTTP '.$status.': '.($response->body() ?: 'Error');
            foreach ($rows as $row) {
                $newAttempts = $row->attempts + 1;
                $failedAt = $newAttempts >= 10 ? $now : null;
                $nextAttempt = $now->copy()->addMinutes(self::backoffMinutes($newAttempts));

                $row->update([
                    'attempts' => $newAttempts,
                    'last_error' => $errorMessage,
                    'next_attempt_at' => $nextAttempt,
                    'failed_at' => $failedAt,
                ]);

                if ($failedAt !== null) {
                    $totalFailed++;
                }
            }

            $lastError = $errorMessage;
            break;
        }

        return new SyncRunResult(
            batches: $batchesProcessed,
            sent: $totalSent,
            rejected: $totalRejected,
            failed: $totalFailed,
            error: $lastError,
        );
    }
}
