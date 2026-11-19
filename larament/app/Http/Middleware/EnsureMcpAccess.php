<?php

namespace App\Http\Middleware;

use App\Mcp\McpAccess;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets an MCP request through only when the vendor enabled «المساعد الذكي»
 * and the token's user may use it. Answers JSON 403, never a redirect.
 */
class EnsureMcpAccess
{
    public function __construct(private readonly McpAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $denial = $user instanceof User ? $this->access->denial($user) : 'غير مصرح.';

        if ($denial !== null) {
            return response()->json(['error' => 'forbidden', 'error_description' => $denial], 403);
        }

        return $next($request);
    }
}
