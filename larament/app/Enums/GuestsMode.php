<?php

namespace App\Enums;

/**
 * Whether the cashier is asked for the number of guests on an order type.
 */
enum GuestsMode: string
{
    case OFF = 'off';
    case OPTIONAL = 'optional';
    case REQUIRED = 'required';

    public function label(): string
    {
        return match ($this) {
            self::OFF => 'لا يُطلب',
            self::OPTIONAL => 'اختياري',
            self::REQUIRED => 'إجباري',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
