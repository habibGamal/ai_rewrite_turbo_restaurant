<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class PeriodPerformanceWidget extends Widget
{
    protected static string $view = 'filament.widgets.placeholder-widget';
    protected static ?string $heading = 'أداء الفترات الزمنية';
    protected static ?string $description = 'قريباً - مقارنة أداء فترات الصباح والظهر والمساء والليل';
}

class StaffOptimizationWidget extends Widget
{
    protected static string $view = 'filament.widgets.placeholder-widget';
    protected static ?string $heading = 'توصيات تحسين العمالة';
    protected static ?string $description = 'قريباً - توصيات لتوزيع الموظفين حسب ساعات الذروة';
}

class CustomerTrafficPatternsWidget extends Widget
{
    protected static string $view = 'filament.widgets.placeholder-widget';
    protected static ?string $heading = 'أنماط حركة العملاء';
    protected static ?string $description = 'قريباً - تحليل كثافة العملاء وأنماط الحركة';
}

class OrderTypeHourlyPerformanceWidget extends Widget
{
    protected static string $view = 'filament.widgets.placeholder-widget';
    protected static ?string $heading = 'أداء أنواع الطلبات حسب الساعة';
    protected static ?string $description = 'قريباً - مقارنة أداء تناول في المكان والتوصيل عبر الساعات';
}

class HourlyPerformanceTableWidget extends Widget
{
    protected static string $view = 'filament.widgets.placeholder-widget';
    protected static ?string $heading = 'جدول الأداء التفصيلي حسب الساعة';
    protected static ?string $description = 'قريباً - بيانات تفصيلية لكل ساعة';
}
