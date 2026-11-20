<?php

namespace App\Filament\Traits;

use App\Modules\ModuleScreens;
use Illuminate\Support\Str;

trait ViewerAccess
{
    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user || ! ModuleScreens::allows(static::class)) {
            return false;
        }

        return $user->can('view_reports') || $user->can(static::getReportPermission());
    }

    public static function getReportPermission(): string
    {
        $baseName = class_basename(static::class);

        return 'view_'.Str::snake($baseName);
    }
}
