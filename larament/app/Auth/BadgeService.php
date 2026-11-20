<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Badge (barcode card) quick login to the POS.
 *
 * A badge is a random 16-digit code printed as a Code 128 barcode. Only its
 * SHA-256 hash is stored, so a lost card is replaced by printing a new one.
 * Users who can open the admin panel (managers) also type a 4-digit PIN.
 * A badge session never opens the admin panel.
 */
class BadgeService
{
    /** Session key marking a login made with a badge. */
    public const SESSION_KEY = 'auth_via_badge';

    /**
     * Give the user a new badge, replacing any earlier one. Returns the plain
     * code, which is shown once for printing and never stored.
     */
    public function issue(User $user): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 16; $i++) {
                $code .= (string) random_int(0, 9);
            }
            $hash = self::hash($code);
        } while (User::withTrashed()->where('badge_hash', $hash)->exists());

        $user->forceFill([
            'badge_hash' => $hash,
            'badge_issued_at' => now(),
        ])->save();

        return $code;
    }

    public function revoke(User $user): void
    {
        $user->forceFill(['badge_hash' => null, 'badge_issued_at' => null])->save();
    }

    public function find(string $code): ?User
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if ($code === '') {
            return null;
        }

        return User::query()->where('badge_hash', self::hash($code))->first();
    }

    public function requiresPin(User $user): bool
    {
        return $user->can('access_admin_panel');
    }

    public function checkPin(User $user, ?string $pin): bool
    {
        $stored = $user->getAttribute('badge_pin');

        return is_string($stored) && $stored !== '' && is_string($pin) && Hash::check($pin, $stored);
    }

    public static function hash(string $code): string
    {
        return hash('sha256', $code);
    }
}
