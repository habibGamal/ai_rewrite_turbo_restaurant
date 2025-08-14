<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class ChannelProfitabilityWidget extends Widget
{
    protected static string $view = 'filament.widgets.placeholder-widget';
    protected static ?string $heading = 'تحليل ربحية القنوات';
    protected static ?string $description = 'قريباً - مقارنة هوامش الربح والتكاليف التشغيلية لكل قناة';
}

class ChannelTrendsWidget extends Widget
{
    protected static string $view = 'filament.widgets.placeholder-widget';
    protected static ?string $heading = 'اتجاهات أداء القنوات';
    protected static ?string $description = 'قريباً - تحليل اتجاهات نمو القنوات عبر الزمن';
}

class CrossChannelBehaviorWidget extends Widget
{
    protected static string $view = 'filament.widgets.placeholder-widget';
    protected static ?string $heading = 'سلوك العملاء عبر القنوات';
    protected static ?string $description = 'قريباً - تحليل العملاء الذين يستخدمون قنوات متعددة';
}
