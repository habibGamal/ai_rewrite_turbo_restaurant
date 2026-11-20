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

    /**
     * Pair the POS with the platform.
     */
    public function pair(string $url, string $code): Response
    {
        $endpoint = rtrim($url, '/').'/api/v1/pair';

        return Http::withHeaders([
            'Accept' => 'application/json',
            'X-Schema-Version' => '1',
            'X-Pos-Version' => (string) config('app.version'),
        ])
            ->timeout(30)
            ->connectTimeout(10)
            ->withOptions(['verify' => true])
            ->asJson()
            ->post($endpoint, [
                'code' => $code,
                'pos_version' => (string) config('app.version'),
            ]);
    }

    /**
     * Rotate the connection token.
     */
    public function rotate(PlatformConnection $connection): Response
    {
        $endpoint = rtrim($connection->url, '/').'/api/v1/pair/rotate';

        return Http::withToken($connection->token)
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Schema-Version' => '1',
                'X-Pos-Version' => (string) config('app.version'),
            ])
            ->timeout(30)
            ->connectTimeout(10)
            ->withOptions(['verify' => true])
            ->asJson()
            ->post($endpoint);
    }

    /**
     * Send heartbeat to the platform.
     *
     * @param  array<string, mixed>  $body
     */
    public function heartbeat(PlatformConnection $connection, array $body): Response
    {
        $endpoint = rtrim($connection->url, '/').'/api/v1/heartbeat';

        return Http::withToken($connection->token)
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Schema-Version' => '1',
                'X-Pos-Version' => (string) config('app.version'),
            ])
            ->timeout(30)
            ->connectTimeout(10)
            ->withOptions(['verify' => true])
            ->asJson()
            ->post($endpoint, $body);
    }
}
