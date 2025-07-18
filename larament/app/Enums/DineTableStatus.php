<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum DineTableStatus: string implements HasColor, HasIcon, HasLabel
{
    case Available = 'available';
    case Occupied = 'occupied';
    case Reserved = 'reserved';

    public function getColor(): ?string
    {
        return match ($this) {
            self::Available => 'success',
            self::Occupied => 'warning',
            self::Reserved => 'danger',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Available => 'heroicon-o-check-circle',
            self::Occupied => 'heroicon-o-user-group',
            self::Reserved => 'heroicon-o-bookmark',
        };
    }

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Available => 'متاحة',
            self::Occupied => 'مشغولة',
            self::Reserved => 'محجوزة',
        };
    }

    public static function toSelectArray(): array
    {
        return [
            self::Available->value => self::Available->getLabel(),
            self::Occupied->value => self::Occupied->getLabel(),
            self::Reserved->value => self::Reserved->getLabel(),
        ];
    }
}
