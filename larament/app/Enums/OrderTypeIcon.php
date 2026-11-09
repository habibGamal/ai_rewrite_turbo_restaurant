<?php

namespace App\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Throwable;

enum OrderTypeIcon: string implements HasIcon, HasLabel
{
    case Home = 'heroicon-o-home';
    case ShoppingBag = 'heroicon-o-shopping-bag';
    case Truck = 'heroicon-o-truck';
    case BuildingOffice = 'heroicon-o-building-office';
    case DevicePhoneMobile = 'heroicon-o-device-phone-mobile';
    case GlobeAlt = 'heroicon-o-globe-alt';
    case ComputerDesktop = 'heroicon-o-computer-desktop';
    case Tag = 'heroicon-o-tag';
    case Users = 'heroicon-o-users';
    case UserGroup = 'heroicon-o-user-group';
    case Briefcase = 'heroicon-o-briefcase';
    case Gift = 'heroicon-o-gift';
    case Star = 'heroicon-o-star';
    case Cake = 'heroicon-o-cake';
    case Sun = 'heroicon-o-sun';
    case Moon = 'heroicon-o-moon';
    case MapPin = 'heroicon-o-map-pin';
    case HomeModern = 'heroicon-o-home-modern';
    case ShoppingCart = 'heroicon-o-shopping-cart';
    case BuildingStorefront = 'heroicon-o-building-storefront';
    case Phone = 'heroicon-o-phone';
    case Sparkles = 'heroicon-o-sparkles';
    case Fire = 'heroicon-o-fire';
    case Beaker = 'heroicon-o-beaker';
    case Heart = 'heroicon-o-heart';
    case Ticket = 'heroicon-o-ticket';

    public function getLabel(): string
    {
        return match ($this) {
            self::Home => 'منزل',
            self::ShoppingBag => 'حقيبة تسوق',
            self::Truck => 'شاحنة توصيل',
            self::BuildingOffice => 'مبنى شركات',
            self::DevicePhoneMobile => 'هاتف محمول',
            self::GlobeAlt => 'إنترنت',
            self::ComputerDesktop => 'حاسوب مكتبي',
            self::Tag => 'علامة',
            self::Users => 'مستخدمون',
            self::UserGroup => 'مجموعة',
            self::Briefcase => 'حقيبة عمل',
            self::Gift => 'هدية',
            self::Star => 'نجمة',
            self::Cake => 'كعكة',
            self::Sun => 'شمس',
            self::Moon => 'قمر',
            self::MapPin => 'موقع',
            self::HomeModern => 'منزل حديث',
            self::ShoppingCart => 'عربة تسوق',
            self::BuildingStorefront => 'واجهة متجر',
            self::Phone => 'هاتف',
            self::Sparkles => 'بريق',
            self::Fire => 'نار',
            self::Beaker => 'دورق',
            self::Heart => 'قلب',
            self::Ticket => 'تذكرة',
        };
    }

    public function getIcon(): string
    {
        return $this->value;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<string, string>
     */
    public static function options(?string $currentIcon = null): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = self::renderOption($case->value, $case->getLabel());
        }

        if ($currentIcon !== null && $currentIcon !== '' && ! isset($options[$currentIcon])) {
            $options[$currentIcon] = self::renderOption($currentIcon, $currentIcon);
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    public static function allowedValues(?string $currentIcon = null): array
    {
        return array_keys(self::options($currentIcon));
    }

    public static function safeIcon(?string $icon): string
    {
        if ($icon === null || $icon === '') {
            return self::Tag->value;
        }

        if (self::tryFrom($icon) !== null) {
            return $icon;
        }

        try {
            svg($icon);

            return $icon;
        } catch (Throwable) {
            return self::Tag->value;
        }
    }

    public static function renderOption(string $icon, string $label): string
    {
        $resolvedIcon = self::safeIcon($icon);
        $svg = svg($resolvedIcon, 'fi-icon fi-size-md inline-block shrink-0')->toHtml();

        return '<span class="inline-flex items-center gap-2">'.$svg.'<span>'.e($label).'</span></span>';
    }
}
