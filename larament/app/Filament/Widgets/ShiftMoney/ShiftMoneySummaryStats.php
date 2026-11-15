<?php

namespace App\Filament\Widgets\ShiftMoney;

use App\Filament\Widgets\ShiftMoney\Concerns\InteractsWithSelectedShift;
use App\Services\FeatureService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\On;

/**
 * Opening, expected and counted cash and the shortage of the picked shift,
 * refreshed after every change on the page.
 */
class ShiftMoneySummaryStats extends BaseWidget
{
    use InteractsWithSelectedShift;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    #[On(self::CHANGED_EVENT)]
    public function refreshSummary(): void
    {
        // Re-render with fresh numbers.
    }

    public function getHeading(): ?string
    {
        $shift = $this->selectedShift();

        if ($shift === null) {
            return 'اختر وردية';
        }

        $state = $this->shiftMoney()->isClosed($shift) ? 'مغلقة' : 'مفتوحة';
        $edited = $shift->money_edited_at !== null
            ? ' · معدلة '.$shift->money_edited_count.' مرة (آخر تعديل '.$shift->money_edited_at->format('Y-m-d H:i').')'
            : '';

        return "وردية #{$shift->id} · {$state}{$edited}";
    }

    protected function getStats(): array
    {
        $shift = $this->selectedShift();

        if ($shift === null) {
            return [];
        }

        $summary = $this->shiftMoney()->summary($shift);
        $money = fn (?float $value): string => $value === null ? '—' : number_format($value, 2).' جنيه';

        $shortage = $summary['shortage'];
        $shortageLabel = $shortage === null ? 'العجز / الزيادة' : ($shortage > 0 ? 'العجز' : ($shortage < 0 ? 'الزيادة' : 'العجز / الزيادة'));

        $stats = [
            Stat::make('نقدية البداية', $money($summary['start_cash']))
                ->color('gray'),
            Stat::make('النقدية المتوقعة', $money($summary['expected_cash']))
                ->description($summary['closed'] ? 'بعد آخر تعديل' : 'حتى الآن')
                ->color('info'),
            Stat::make('النقدية الفعلية (المعدودة)', $money($summary['counted_cash']))
                ->description($summary['closed'] ? 'لا تتغير بالتعديل' : 'الوردية لم تغلق بعد')
                ->color('gray'),
            Stat::make($shortageLabel, $money($shortage === null ? null : abs($shortage)))
                ->color($shortage === null || $shortage == 0.0 ? 'gray' : ($shortage > 0 ? 'danger' : 'success')),
            Stat::make('المصروفات', $money($summary['expenses']))
                ->color('danger'),
        ];

        if (app(FeatureService::class)->revenuesEnabled()) {
            $stats[] = Stat::make('الإيرادات الأخرى', $money($summary['revenues']))->color('success');
        }

        return $stats;
    }
}
