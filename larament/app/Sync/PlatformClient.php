<?php

namespace App\Sync;

use App\Models\PlatformConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PlatformClient
{
    /**
     * Send a batch of documents to the platform ingestion endpoint.
     *
     * @param  array<string, mixed>  $envelope
     */
    public function sendBatch(PlatformConnection $connection, array $envelope, string $batchUuid): Response
    {
        $json = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $compressed = gzencode($json, 6);

        if ($compressed === false) {
            throw new RuntimeException('Failed to gzip encode sync batch payload');
        }

        $url = rtrim($connection->url, '/').'/api/v1/ingest/batches';

        return Http::withToken($connection->token)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Content-Encoding' => 'gzip',
                'Idempotency-Key' => $batchUuid,
                'X-Schema-Version' => '1',
                'X-Pos-Version' => (string) config('app.version'),
            ])
            ->timeout(30)
            ->connectTimeout(10)
            ->withOptions(['verify' => true])
            ->withBody($compressed, 'application/json')
            ->post($url);
    }
}
