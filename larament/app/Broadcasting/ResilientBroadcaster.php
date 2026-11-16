<?php

namespace App\Broadcasting;

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Wraps a real-time broadcaster (Reverb/Pusher) so that a down or slow socket
 * server never fails the request or job that triggered the broadcast. Screens
 * refresh on their own, so a lost live update is logged and dropped; the sale,
 * web order or kitchen change that caused it has already been saved.
 *
 * Channel auth (auth/validAuthenticationResponse) and channel registration are
 * passed straight through, unchanged.
 */
final class ResilientBroadcaster implements Broadcaster
{
    public function __construct(
        private readonly Broadcaster $inner,
        private readonly string $connection,
    ) {}

    public function auth($request)
    {
        return $this->inner->auth($request);
    }

    public function validAuthenticationResponse($request, $result)
    {
        return $this->inner->validAuthenticationResponse($request, $result);
    }

    /**
     * @param  array<int, mixed>  $channels
     * @param  string  $event
     * @param  array<string, mixed>  $payload
     */
    public function broadcast(array $channels, $event, array $payload = []): void
    {
        try {
            $this->inner->broadcast($channels, $event, $payload);
        } catch (Throwable $e) {
            Log::warning('Broadcast skipped: the real-time server is unreachable', [
                'connection' => $this->connection,
                'event' => $event,
                'channels' => array_map(fn (mixed $channel): string => (string) $channel, $channels),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function inner(): Broadcaster
    {
        return $this->inner;
    }

    /**
     * Channel registration (Broadcast::channel) and other broadcaster helpers.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->inner->{$method}(...$arguments);
    }
}
