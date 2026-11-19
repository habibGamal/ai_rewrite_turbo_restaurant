<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Enums\OptionalFeature;
use App\Models\User;
use App\Services\FeatureService;
use Illuminate\Support\Facades\Auth;

/**
 * Who may run direct read-only queries: the vendor feature must be on, and
 * the user must be the vendor (الدعم الفني) or a shop admin.
 */
final class AiQueryAccess
{
    public static function allowed(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && app(FeatureService::class)->isEnabled(OptionalFeature::AI_QUERIES)
            && ($user->isVendor() || $user->hasRole('admin'));
    }
}
