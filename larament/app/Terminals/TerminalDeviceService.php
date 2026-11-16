<?php

namespace App\Terminals;

use App\Models\Terminal;
use App\Models\TerminalDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie as HttpCookie;

/**
 * Which drawer a browser belongs to. Binding gives the browser a random token
 * in a long-lived cookie; the server keeps only the token's hash.
 */
class TerminalDeviceService
{
    public const COOKIE = 'pos_terminal_device';

    /** Five years: a device stays bound until a manager rebinds or unbinds it. */
    private const COOKIE_MINUTES = 60 * 24 * 365 * 5;

    public function fromRequest(Request $request): ?TerminalDevice
    {
        $token = $request->cookie(self::COOKIE);

        if (! is_string($token) || $token === '') {
            return null;
        }

        return TerminalDevice::query()
            ->where('token_hash', hash('sha256', $token))
            ->with('terminal')
            ->first();
    }

    /**
     * Bind the browser of this request to a drawer, replacing any earlier
     * binding of the same browser. Returns the cookie to attach.
     */
    public function bind(Request $request, Terminal $terminal, User $by): HttpCookie
    {
        $this->fromRequest($request)?->delete();

        $token = Str::random(64);

        TerminalDevice::query()->create([
            'terminal_id' => $terminal->getKey(),
            'token_hash' => hash('sha256', $token),
            'label' => Str::limit((string) $request->userAgent(), 180, ''),
            'bound_by' => $by->getKey(),
            'last_seen_at' => now(),
        ]);

        return Cookie::make(self::COOKIE, $token, self::COOKIE_MINUTES, httpOnly: true, sameSite: 'lax');
    }

    public function touch(TerminalDevice $device): void
    {
        if ($device->last_seen_at === null || $device->last_seen_at->lt(now()->subMinutes(10))) {
            $device->forceFill(['last_seen_at' => now()])->saveQuietly();
        }
    }
}
