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
                $body = $response->json();

                // Validate returned response before marking rows sent
                $isValidResponse = is_array($body)
                    && isset($body['accepted'])
                    && is_array($body['accepted'])
                    && (! isset($body['rejected']) || is_array($body['rejected']));

                $errorMessage = null;

                if (! $isValidResponse) {
                    $errorMessage = 'Invalid response payload from platform';
                }

                // Validate response batch_uuid equals sent batch UUID where provided
                if ($isValidResponse && array_key_exists('batch_uuid', $body) && $body['batch_uuid'] !== null) {
                    if (! is_string($body['batch_uuid']) || $body['batch_uuid'] !== $batch->uuid) {
                        $isValidResponse = false;
                        $errorMessage = 'Batch UUID mismatch: expected '.$batch->uuid.', got '.(is_scalar($body['batch_uuid']) ? (string) $body['batch_uuid'] : gettype($body['batch_uuid']));
                    }
                }

                // Map submitted documents and UUIDs
                $submittedDocs = [];
                $submittedUuids = [];
                $docsPerUuid = [];
                foreach ($documents as $doc) {
                    $t = (string) $doc['type'];
                    $u = (string) $doc['uuid'];
                    $submittedDocs[$t.':'.$u] = ['type' => $t, 'uuid' => $u];
                    $submittedUuids[$u] = true;
                    $docsPerUuid[$u] = ($docsPerUuid[$u] ?? 0) + 1;
                }

                // Parse rejections
                $rejectedList = $body['rejected'] ?? [];
                $rejectedByTypeUuid = [];
                $rejectedByUuidOnly = [];

                if ($isValidResponse) {
                    foreach ($rejectedList as $item) {
                        if (is_array($item) && isset($item['uuid'])) {
                            $u = (string) $item['uuid'];
                            $reason = (string) ($item['reason'] ?? 'Rejected by platform');
                            $t = isset($item['type']) && is_string($item['type']) && $item['type'] !== '' ? $item['type'] : null;

                            if ($t !== null) {
                                if (! isset($submittedDocs[$t.':'.$u])) {
                                    $isValidResponse = false;
                                    $errorMessage = "Unknown document in rejected list: {$t}:{$u}";
                                    break;
                                }
                                $rejectedByTypeUuid[$t.':'.$u] = $reason;
                            } else {
                                if (! isset($submittedUuids[$u])) {
                                    $isValidResponse = false;
                                    $errorMessage = "Unknown UUID in rejected list: {$u}";
                                    break;
                                }
                                $rejectedByUuidOnly[$u] = $reason;
                            }
                        } elseif (is_string($item)) {
                            if (! isset($submittedUuids[$item])) {
                                $isValidResponse = false;
                                $errorMessage = "Unknown UUID in rejected list: {$item}";
                                break;
                            }
                            $rejectedByUuidOnly[$item] = 'Rejected by platform';
                        } else {
                            $isValidResponse = false;
                            $errorMessage = 'Malformed item in rejected list';
                            break;
                        }
                    }
                }

                // Parse acceptances
                $acceptedList = $body['accepted'] ?? [];
                $acceptedByTypeUuid = [];
                $acceptedByUuidOnly = [];

                if ($isValidResponse) {
                    foreach ($acceptedList as $item) {
                        if (is_array($item) && isset($item['uuid'])) {
                            $u = (string) $item['uuid'];
                            $t = isset($item['type']) && is_string($item['type']) && $item['type'] !== '' ? $item['type'] : null;

                            if ($t !== null) {
                                if (! isset($submittedDocs[$t.':'.$u])) {
                                    $isValidResponse = false;
                                    $errorMessage = "Unknown document in accepted list: {$t}:{$u}";
                                    break;
                                }
                                $acceptedByTypeUuid[$t.':'.$u] = true;
                            } else {
                                if (! isset($submittedUuids[$u])) {
                                    $isValidResponse = false;
                                    $errorMessage = "Unknown UUID in accepted list: {$u}";
                                    break;
                                }
                                $acceptedByUuidOnly[$u] = true;
                            }
                        } elseif (is_string($item)) {
                            if (! isset($submittedUuids[$item])) {
                                $isValidResponse = false;
                                $errorMessage = "Unknown UUID in accepted list: {$item}";
                                break;
                            }
                            $acceptedByUuidOnly[$item] = true;
                        } else {
                            $isValidResponse = false;
                            $errorMessage = 'Malformed item in accepted list';
                            break;
                        }
                    }
                }

                // Validate every submitted document is accounted for without omission or contradiction
                $resolvedRejections = [];
                $resolvedAcceptances = [];

                if ($isValidResponse) {
                    foreach ($submittedDocs as $docKey => $doc) {
                        $t = $doc['type'];
                        $u = $doc['uuid'];

                        $isRejected = isset($rejectedByTypeUuid[$docKey]) || isset($rejectedByUuidOnly[$u]);
                        $rejectionReason = $rejectedByTypeUuid[$docKey] ?? ($rejectedByUuidOnly[$u] ?? null);

                        if (isset($acceptedByTypeUuid[$docKey]) && isset($rejectedByTypeUuid[$docKey])) {
                            $isValidResponse = false;
                            $errorMessage = "Document {$docKey} is both accepted and rejected";
                            break;
                        }

                        if (($docsPerUuid[$u] ?? 1) === 1 && isset($acceptedByUuidOnly[$u]) && (isset($rejectedByTypeUuid[$docKey]) || isset($rejectedByUuidOnly[$u]))) {
                            $isValidResponse = false;
                            $errorMessage = "Document {$docKey} is both accepted and rejected";
                            break;
                        }

                        if (isset($rejectedByUuidOnly[$u]) && isset($acceptedByUuidOnly[$u])) {
                            $isValidResponse = false;
                            $errorMessage = "Conflicting untyped acceptance and rejection for UUID {$u}";
                            break;
                        }

                        // An accepted UUID is accepted only if this (type, uuid) is not rejected
                        $isAccepted = (! $isRejected) && (isset($acceptedByTypeUuid[$docKey]) || isset($acceptedByUuidOnly[$u]));

                        if ($isRejected) {
                            $resolvedRejections[$docKey] = $rejectionReason ?? 'Rejected by platform';
                        } elseif ($isAccepted) {
                            $resolvedAcceptances[$docKey] = true;
                        } else {
                            $isValidResponse = false;
                            $errorMessage = "Incomplete response: missing entry for document {$docKey}";
                            break;
                        }
                    }
                }

                // On any invalid, incomplete or inconsistent response: treat as failure and preserve batch for retry
                if (! $isValidResponse) {
                    $errorMessage = $errorMessage ?? 'Invalid response payload from platform';
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

                $acceptedRowIds = [];
                $rejectedRows = [];
                $acceptedDistinctDocs = [];
                $rejectedDistinctDocs = [];

                foreach ($rows as $row) {
                    $docKey = $row->doc_type.':'.$row->doc_uuid;

                    if (isset($resolvedRejections[$docKey])) {
                        $rejectedRows[] = [$row, $resolvedRejections[$docKey]];
                        $rejectedDistinctDocs[$docKey] = true;
                    } elseif (isset($resolvedAcceptances[$docKey])) {
                        $acceptedRowIds[] = $row->id;
                        $acceptedDistinctDocs[$docKey] = true;
                    }
                }

                // Mark accepted rows as sent
                if (! empty($acceptedRowIds)) {
                    SyncOutbox::whereIn('id', $acceptedRowIds)->update([
                        'sent_at' => $now,
                        'last_error' => null,
                    ]);
                }

                // Update rejected rows with backoff and release batch_id
                foreach ($rejectedRows as [$row, $reason]) {
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

                $hasRejections = ! empty($rejectedRows);
                $batch->update([
                    'status' => $hasRejections ? 'partial' : 'sent',
                    'response' => $body,
                ]);

                $connection->update([
                    'last_success_at' => $now,
                ]);

                $batchesProcessed++;
                $totalSent += count($acceptedDistinctDocs);
                $totalRejected += count($rejectedDistinctDocs);

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
