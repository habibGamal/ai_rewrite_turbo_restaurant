<?php

namespace App\Exports;

use App\Services\ChannelPerformanceReportService;
use App\Services\FeatureService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * The channels comparison table as an Excel sheet (right to left), one row
 * per channel plus the totals row, with the change columns when a
 * comparison period is set.
 */
class ChannelComparisonExport implements FromArray, ShouldAutoSize, WithEvents, WithHeadings, WithTitle
{
    /**
     * @param  array<string, mixed>  $report  ChannelPerformanceReportService::report()
     */
    public function __construct(private readonly array $report) {}

    public function title(): string
    {
        return 'مقارنة القنوات';
    }

    public function headings(): array
    {
        $headings = ['القناة', 'الطلبات', 'صافي المبيعات', 'الحصة %', 'متوسط الطلب', 'الخصومات', 'خدمة ورسوم ومعدات', 'مجمل الربح', 'الهامش %', 'المرتجعات', 'نسبة المرتجع %', 'الملغي', 'نسبة الإلغاء %', 'العملاء', 'الأفراد', 'متوسط الفرد'];

        if (! app(FeatureService::class)->returnsEnabled()) {
            array_splice($headings, 9, 2);
        }

        if ($this->report['previous_totals'] !== null) {
            array_push($headings, 'تغير الطلبات %', 'تغير صافي المبيعات %', 'تغير متوسط الطلب %');
        }

        return $headings;
    }

    public function array(): array
    {
        $lines = [];
        foreach ([...array_values($this->report['rows']), $this->report['totals']] as $row) {
            $line = [
                $row['label'], $row['orders'], $row['net_sales'], $row['sales_share'], $row['avg_order'],
                $row['discount'], $row['charges'], $row['net_profit'], $row['margin'], $row['refunds'],
                $row['refund_rate'], $row['cancelled'], $row['cancel_rate'], $row['customers'], $row['guests'], $row['avg_per_guest'],
            ];

            if (! app(FeatureService::class)->returnsEnabled()) {
                array_splice($line, 9, 2);
            }

            if ($this->report['previous_totals'] !== null) {
                $before = $row['code'] === null ? $this->report['previous_totals'] : ($this->report['previous_rows'][$row['code']] ?? null);
                foreach (['orders', 'net_sales', 'avg_order'] as $key) {
                    $line[] = ChannelPerformanceReportService::change($row[$key], $before[$key] ?? null);
                }
            }

            $lines[] = $line;
        }

        return $lines;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $event->sheet->getDelegate()->setRightToLeft(true);
                $event->sheet->getDelegate()->getStyle('1:1')->getFont()->setBold(true);
            },
        ];
    }
}
