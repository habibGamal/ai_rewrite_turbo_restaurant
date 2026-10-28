<?php

namespace App\Filament\Components;

use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Illuminate\Support\HtmlString;

class DailyComparisonFilterFormComponent
{
    public static function make(
        string $description = 'اختر نوع المقارنة والفترة الزمنية لتحليل ومقارنة أداء الأيام والشهور'
    ): Section {
        $currentYear = (int) now()->year;
        $currentMonth = (int) now()->month;

        $monthOptions = [
            1 => 'يناير',
            2 => 'فبراير',
            3 => 'مارس',
            4 => 'أبريل',
            5 => 'مايو',
            6 => 'يونيو',
            7 => 'يوليو',
            8 => 'أغسطس',
            9 => 'سبتمبر',
            10 => 'أكتوبر',
            11 => 'نوفمبر',
            12 => 'ديسمبر',
        ];

        $yearOptions = [
            $currentYear - 2 => (string) ($currentYear - 2),
            $currentYear - 1 => (string) ($currentYear - 1),
            $currentYear => (string) $currentYear,
            $currentYear + 1 => (string) ($currentYear + 1),
        ];

        return Section::make('خيارات المقارنة والفترة الزمنية')
            ->description($description)
            ->columnSpanFull()
            ->schema([
                Select::make('presetPeriod')
                    ->label('الفترة الزمنية')
                    ->options([
                        'this_month' => 'هذا الشهر',
                        'last_month' => 'الشهر الماضي',
                        'this_week' => 'هذا الأسبوع (السبت - الجمعة)',
                        'last_week' => 'الأسبوع الماضي (السبت - الجمعة)',
                        'last_30_days' => 'آخر 30 يوم',
                        'last_3_months' => 'آخر 3 شهور',
                        'this_year' => 'هذا العام',
                        'custom_month' => 'شهر محدد',
                        'custom' => 'فترة مخصصة',
                    ])
                    ->default('this_month')
                    ->live()
                    ->afterStateUpdated(function (callable $set, callable $get, $state) {
                        match ($state) {
                            'this_month' => [
                                $set('startDate', now()->startOfMonth()->toDateString()),
                                $set('endDate', now()->endOfMonth()->toDateString()),
                            ],
                            'last_month' => [
                                $set('startDate', now()->subMonth()->startOfMonth()->toDateString()),
                                $set('endDate', now()->subMonth()->endOfMonth()->toDateString()),
                            ],
                            'this_week' => [
                                $set('startDate', now()->startOfWeek(Carbon::SATURDAY)->toDateString()),
                                $set('endDate', now()->endOfWeek(Carbon::FRIDAY)->toDateString()),
                            ],
                            'last_week' => [
                                $set('startDate', now()->subWeek()->startOfWeek(Carbon::SATURDAY)->toDateString()),
                                $set('endDate', now()->subWeek()->endOfWeek(Carbon::FRIDAY)->toDateString()),
                            ],
                            'last_30_days' => [
                                $set('startDate', now()->subDays(29)->startOfDay()->toDateString()),
                                $set('endDate', now()->endOfDay()->toDateString()),
                            ],
                            'last_3_months' => [
                                $set('startDate', now()->subMonths(2)->startOfMonth()->toDateString()),
                                $set('endDate', now()->endOfMonth()->toDateString()),
                            ],
                            'this_year' => [
                                $set('startDate', now()->startOfYear()->toDateString()),
                                $set('endDate', now()->endOfYear()->toDateString()),
                            ],
                            'custom_month' => [
                                $year = (int) ($get('selectedYear') ?: now()->year),
                                $month = (int) ($get('selectedMonth') ?: now()->month),
                                $date = Carbon::createFromDate($year, $month, 1),
                                $set('startDate', $date->copy()->startOfMonth()->toDateString()),
                                $set('endDate', $date->copy()->endOfMonth()->toDateString()),
                            ],
                            default => null,
                        };
                    }),

                Select::make('viewType')
                    ->label('طريقة المقارنة والعرض')
                    ->options([
                        'by_date' => 'عرض يومي تسلسلي (أيام الفترة)',
                        'by_day_of_week' => 'مقارنة مجمعة لأيام الأسبوع (السبت - الجمعة)',
                        'by_month' => 'مقارنة الشهور',
                    ])
                    ->default('by_date')
                    ->live(),

                Select::make('selectedMonth')
                    ->label('الشهر')
                    ->options($monthOptions)
                    ->default($currentMonth)
                    ->visible(fn (callable $get) => $get('presetPeriod') === 'custom_month')
                    ->live()
                    ->afterStateUpdated(function (callable $set, callable $get, $state) {
                        $year = (int) ($get('selectedYear') ?: now()->year);
                        $month = (int) ($state ?: now()->month);
                        $date = Carbon::createFromDate($year, $month, 1);
                        $set('startDate', $date->copy()->startOfMonth()->toDateString());
                        $set('endDate', $date->copy()->endOfMonth()->toDateString());
                    }),

                Select::make('selectedYear')
                    ->label('السنة')
                    ->options($yearOptions)
                    ->default($currentYear)
                    ->visible(fn (callable $get) => $get('presetPeriod') === 'custom_month')
                    ->live()
                    ->afterStateUpdated(function (callable $set, callable $get, $state) {
                        $year = (int) ($state ?: now()->year);
                        $month = (int) ($get('selectedMonth') ?: now()->month);
                        $date = Carbon::createFromDate($year, $month, 1);
                        $set('startDate', $date->copy()->startOfMonth()->toDateString());
                        $set('endDate', $date->copy()->endOfMonth()->toDateString());
                    }),

                DatePicker::make('startDate')
                    ->label('تاريخ البداية')
                    ->default(now()->startOfMonth()->toDateString())
                    ->disabled(fn (callable $get) => $get('presetPeriod') !== 'custom')
                    ->live(),

                DatePicker::make('endDate')
                    ->label('تاريخ النهاية')
                    ->default(now()->endOfMonth()->toDateString())
                    ->disabled(fn (callable $get) => $get('presetPeriod') !== 'custom')
                    ->live(),

                Placeholder::make('shift_comparison_notice')
                    ->hiddenLabel()
                    ->content(new HtmlString(
                        '<div class="flex items-start gap-2.5 p-3 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-700 dark:text-amber-300 text-xs sm:text-sm leading-relaxed">'
                        .'<span class="text-base select-none">💡</span>'
                        .'<div>'
                        .'<span class="font-bold">ملاحظة حول المطابقة مع تقرير الشفتات: </span>'
                        .'يعتمد هذا التقرير على <strong>التاريخ الفعلي لتوقيت الطلب</strong> (التقويم الميلادي)، بينما يعتمد تقرير الشفتات على <strong>تاريخ فتح الشفت</strong>. إذا امتد شفت ليلي لما بعد منتصف الليل، تُحسب طلبات ما بعد منتصف الليل في اليوم والشهر الجديد هنا، بينما تُنسب في تقرير الشفتات لوردية اليوم السابق.'
                        .'</div>'
                        .'</div>'
                    ))
                    ->columnSpanFull(),
            ])
            ->columns(4);
    }
}
