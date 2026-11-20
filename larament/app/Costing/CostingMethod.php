<?php

namespace App\Costing;

enum CostingMethod: string
{
    case Off = 'off';
    case Immediate = 'immediate';
    case WeightedAverage = 'weighted_average';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'إيقاف',
            self::Immediate => 'فوري (آخر سعر)',
            self::WeightedAverage => 'متوسط مرجح',
        };
    }

    public static function options(): array
    {
        return array_column(array_map(fn (self $method) => ['value' => $method->value, 'label' => $method->label()], self::cases()), 'label', 'value');
    }
}
