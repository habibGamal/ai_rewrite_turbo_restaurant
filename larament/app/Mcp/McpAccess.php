<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Enums\OptionalFeature;
use App\Models\User;
use App\Services\FeatureService;

/**
 * Who may connect an AI client: the vendor feature must be on and the user
 * must hold use_ai_assistant (the vendor always may). The consent page and
 * the MCP endpoint both ask here.
 */
final class McpAccess
{
    public const PERMISSION = 'use_ai_assistant';

    public function __construct(private readonly FeatureService $features) {}

    /** Why the user may not connect, in Arabic, or null when they may. */
    public function denial(User $user): ?string
    {
        if (! $this->features->isEnabled(OptionalFeature::AI_ASSISTANT)) {
            return OptionalFeature::AI_ASSISTANT->disabledErrorMessage();
        }

        if (! $user->can(self::PERMISSION)) {
            return 'لا تملك صلاحية «استخدام المساعد الذكي». اطلب من مدير المتجر منحك إياها.';
        }

        return null;
    }
}
