<?php

namespace App\Sync;

use App\Models\PlatformConnection;
use App\Models\SyncOutbox;
use App\Services\InventoryDailyAggregationService;
use Carbon\Carbon;
use Throwable;

class HeartbeatService
{
    public function __construct(
        protected PlatformClient $client,
        protected BackfillService $backfill,
        protected InventoryDailyAggregationService $inventoryDailyAggregation,
    ) {}

    /**
     * Send heartbeat to the central platform and process commands in response.
     *
     * @return array<string, mixed>
     */
    public function beat(): array
    {
        $connection = PlatformConnection::active();

        if (! $connection) {
            return [];
        }

        $pendingQuery = SyncOutbox::whereNull('sent_at')->whereNull('failed_at');
        $pendingCount = (int) (clone $pendingQuery)->count();
        $oldestPending = (clone $pendingQuery)->oldest('occurred_at')->value('occurred_at');
        $failedCount = (int) SyncOutbox::whereNotNull('failed_at')->count();

        $openDay = $this->inventoryDailyAggregation->dayStatus();

        $body = [
            'pos_version' => (string) config('app.version'),
            'pending_count' => $pendingCount,
            'oldest_pending_at' => $oldestPending ? Payload::ts(Carbon::parse($oldestPending)) : null,
            'failed_count' => $failedCount,
            'last_success_at' => $connection->last_success_at ? Payload::ts(Carbon::parse($connection->last_success_at)) : null,
            'server_time' => Payload::ts(now()),
            'business_date_open' => $openDay?->toDateString(),
        ];

        try {
            $response = $this->client->heartbeat($connection, $body);
        } catch (Throwable $e) {
            $connection->update([
                'last_error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Heartbeat failed: '.$e->getMessage(), 0, $e);
        }

        if ($response->status() === 401) {
            $connection->update([
                'status' => 'revoked',
                'last_error' => 'token rejected',
            ]);
            PlatformConnection::flushCache();

            throw new \RuntimeException('Heartbeat failed: token rejected');
        }

        if (! $response->successful()) {
            $message = 'Heartbeat failed with HTTP status '.$response->status();
            $connection->update([
                'last_error' => $message,
            ]);

            throw new \RuntimeException($message);
        }

        $connection->update([
            'last_heartbeat_at' => now(),
            'last_error' => null,
        ]);

        $responseData = (array) $response->json();
        $commands = $responseData['commands'] ?? [];

        if (is_array($commands)) {
            foreach ($commands as $command) {
                if (! is_array($command) || empty($command['type'])) {
                    continue;
                }

                $type = $command['type'];

                if ($type === 'resync') {
                    try {
                        $fromStr = $command['from'] ?? null;
                        $toStr = $command['to'] ?? null;

                        if (! is_string($fromStr) || ! is_string($toStr)) {
                            continue;
                        }

                        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromStr) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $toStr)) {
                            continue;
                        }

                        [$y1, $m1, $d1] = explode('-', $fromStr);
                        [$y2, $m2, $d2] = explode('-', $toStr);

                        if (! checkdate((int) $m1, (int) $d1, (int) $y1) || ! checkdate((int) $m2, (int) $d2, (int) $y2)) {
                            continue;
                        }

                        $from = Carbon::createFromFormat('Y-m-d', $fromStr)->startOfDay();
                        $to = Carbon::createFromFormat('Y-m-d', $toStr)->startOfDay();

                        if ($from->gt($to)) {
                            continue;
                        }

                        $this->backfill->queueRange($from, $to);
                    } catch (Throwable) {
                        // Isolate malformed resync command
                        continue;
                    }
                } elseif ($type === 'rotate_token') {
                    try {
                        $rotateResponse = $this->client->rotate($connection);
                        if ($rotateResponse->successful()) {
                            $newToken = $rotateResponse->json('token');
                            if (is_string($newToken) && $newToken !== '') {
                                $connection->update([
                                    'token' => $newToken,
                                ]);
                                PlatformConnection::flushCache();
                            }
                        } elseif ($rotateResponse->status() === 401) {
                            $connection->update([
                                'status' => 'revoked',
                                'last_error' => 'token rejected during rotation',
                            ]);
                            PlatformConnection::flushCache();
                        } else {
                            $connection->update([
                                'last_error' => 'Token rotation failed with HTTP status '.$rotateResponse->status(),
                            ]);
                        }
                    } catch (Throwable $e) {
                        $connection->update([
                            'last_error' => 'Token rotation failed: '.$e->getMessage(),
                        ]);
                    }
                }
                // Unknown commands are ignored
            }
        }

        return $responseData;
    }
}
