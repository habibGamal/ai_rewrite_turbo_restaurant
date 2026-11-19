<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Models\McpToolCall;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Client;
use Throwable;

/**
 * Writes one McpToolCall row per tool call. Never throws: a logging problem
 * must not break the answer.
 */
final class ToolCallLog
{
    /** @var array<string, string|null> client id => name, per request */
    private static array $clientNames = [];

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function record(string $tool, array $arguments, bool $ok, ?string $error, int $durationMs): void
    {
        try {
            $user = Auth::user();
            $clientId = null;

            if ($user instanceof User) {
                $token = $user->currentAccessToken();
                if ($token instanceof AccessToken) {
                    $clientId = (string) $token->oauth_client_id;
                }
            }

            McpToolCall::query()->create([
                'user_id' => $user?->getAuthIdentifier(),
                'client_id' => $clientId,
                'client_name' => $clientId !== null ? self::clientName($clientId) : null,
                'tool' => $tool,
                'arguments' => $arguments === [] ? null : $arguments,
                'ok' => $ok,
                'error' => $error !== null ? Str::limit($error, 490) : null,
                'duration_ms' => max(0, $durationMs),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function clientName(string $clientId): ?string
    {
        if (! array_key_exists($clientId, self::$clientNames)) {
            $name = Client::query()->whereKey($clientId)->value('name');
            self::$clientNames[$clientId] = is_string($name) ? $name : null;
        }

        return self::$clientNames[$clientId];
    }
}
