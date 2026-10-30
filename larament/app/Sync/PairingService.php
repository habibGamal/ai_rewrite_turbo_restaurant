<?php

namespace App\Sync;

use App\Models\PlatformConnection;
use App\Sync\Exceptions\PairingFailed;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
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

        $connection = DB::transaction(function () use ($cleanUrl, $data) {
            PlatformConnection::where('status', '!=', 'revoked')->update(['status' => 'revoked']);

            $connection = PlatformConnection::create([
                'url' => $cleanUrl,
                'branch_uuid' => (string) $data['branch_uuid'],
                'token' => (string) $data['token'],
                'branch_secret' => (string) $data['branch_secret'],
                'timezone' => (string) $data['timezone'],
                'status' => 'active',
                'paired_at' => now(),
            ]);

            PlatformConnection::flushCache();

            return $connection;
        });

        // After saving, queue catalog and 90 days of history
        $today = Carbon::today();
        $this->backfill->queueCatalog();
        $this->backfill->queueRange($today->copy()->subDays(90), $today);

        return $connection;
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
