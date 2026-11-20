<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum KdsSound: string implements HasColor, HasIcon, HasLabel
{
    case NONE = 'none';
    case CHIME = 'chime';
    case BELL = 'bell';
    case BEEP = 'beep';
    case ALERT = 'alert';

    public function getLabel(): string
    {
        return match ($this) {
            self::NONE => 'بدون صوت',
            self::CHIME => 'رنين',
            self::BELL => 'جرس',
            self::BEEP => 'صفارة',
            self::ALERT => 'تنبيه',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NONE => 'gray',
            self::CHIME => 'success',
            self::BELL => 'info',
            self::BEEP => 'warning',
            self::ALERT => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::NONE => 'heroicon-o-speaker-x-mark',
            self::CHIME => 'heroicon-o-musical-note',
            self::BELL => 'heroicon-o-bell',
            self::BEEP => 'heroicon-o-speaker-wave',
            self::ALERT => 'heroicon-o-exclamation-triangle',
        };
    }

    public function label(): string
    {
        return $this->getLabel();
    }
}
