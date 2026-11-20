<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Why a wasted line was thrown away. Optional on each line of a waste
 * record; «تقرير حركة المخزون» groups the waste value by it.
 */
enum WasteReason: string implements HasLabel
{
    case EXPIRED = 'expired';
    case DAMAGED = 'damaged';
    case SPOILED = 'spoiled';
    case PREPARATION = 'preparation';
    case MISTAKE = 'mistake';
    case OTHER = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::EXPIRED => 'انتهاء صلاحية',
            self::DAMAGED => 'كسر أو تلف',
            self::SPOILED => 'فساد في التخزين',
            self::PREPARATION => 'هالك تحضير',
            self::MISTAKE => 'غلط في التحضير أو الطلب',
            self::OTHER => 'أخرى',
        };
    }
}
