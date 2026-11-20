<?php

namespace App\Sync;

use App\Models\PlatformConnection;
use App\Sync\Exceptions\PairingFailed;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PairingService
{
    public function __construct(
        protected PlatformClient $client,
        protected BackfillService $backfill,
    ) {}

    /**
     * Pair the POS with the central platform.
     *
     * @throws PairingFailed
     */
    public function pair(string $url, string $code): PlatformConnection
    {
        $cleanUrl = rtrim(trim($url), '/');

        try {
            $response = $this->client->pair($cleanUrl, $code);
        } catch (Throwable $e) {
            throw new PairingFailed('فشل ربط المنصة: تعذر الاتصال بالخادم ('.$e->getMessage().')', previous: $e);
        }

        if (! $response->successful()) {
            $status = $response->status();
            $message = match ($status) {
                422 => 'فشل ربط المنصة: كود الربط غير صالح أو منتهي الصلاحية',
                401, 403 => 'فشل ربط المنصة: غير مصرح بالربط',
                404 => 'فشل ربط المنصة: خادم المنصة غير موجود بالرابط المحدد',
                default => 'فشل ربط المنصة: حدث خطأ أثناء الاتصال بالخادم ('.$status.')',
            };

            throw new PairingFailed($message);
        }

        $data = $response->json();
        if (
            ! is_array($data)
            || empty($data['branch_uuid'])
            || empty($data['token'])
            || empty($data['branch_secret'])
            || empty($data['timezone'])
        ) {
            throw new PairingFailed('فشل ربط المنصة: استجابة الخادم غير صالحة أو ناقصة');
        }

        $today = Carbon::today();
        $rangeFrom = $today->copy()->subDays(90)->toDateString();
        $rangeTo = $today->toDateString();

        $connection = DB::transaction(function () use ($cleanUrl, $data, $rangeFrom, $rangeTo) {
            PlatformConnection::where('status', '!=', 'revoked')->update(['status' => 'revoked']);

            $connection = PlatformConnection::create([
                'url' => $cleanUrl,
                'branch_uuid' => (string) $data['branch_uuid'],
                'token' => (string) $data['token'],
                'branch_secret' => (string) $data['branch_secret'],
                'timezone' => (string) $data['timezone'],
                'status' => 'active',
                'paired_at' => now(),
                'backfill_status' => 'pending',
                'backfill_catalog_queued' => false,
                'backfill_range_queued' => false,
                'backfill_range_from' => $rangeFrom,
                'backfill_range_to' => $rangeTo,
                'backfill_attempts' => 0,
                'backfill_next_attempt_at' => null,
                'backfill_completed_at' => null,
                'backfill_lock_owner' => null,
                'backfill_locked_at' => null,
            ]);

            PlatformConnection::flushCache();

            return $connection;
        });

        // Attempt initial backfill immediately
        try {
            $this->backfill->processInitialBackfill($connection);
        } catch (Throwable $e) {
            Log::error('Initial backfill failed after pairing: '.$e->getMessage(), [
                'connection_id' => $connection->id,
                'exception' => $e,
            ]);
        } finally {
            PlatformConnection::flushCache();
        }

        return $connection->fresh();
    }

    /**
     * Unpair the POS from the central platform.
     */
    public function unpair(): void
    {
        PlatformConnection::where('status', 'active')->update(['status' => 'revoked']);
        PlatformConnection::flushCache();
    }
}
