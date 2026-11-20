@php
    use App\Services\ChannelPerformanceReportService as Channels;
    $money = fn ($value) => number_format((float) $value, 2);
    $pct = fn ($value) => number_format((float) $value, abs((float) $value) < 1 && (float) $value != 0 ? 2 : 1).'%';
    // Change against the comparison period, coloured the good way.
    $delta = function (?array $before, string $key, float $now, bool $higherIsBetter = true) {
        if ($before === null) {
            return null;
        }
        $change = Channels::change($now, $before[$key] ?? 0);
        if ($change === null) {
            if ($now == 0) {
                return null;
            }

            return ['text' => 'جديد', 'class' => 'text-gray-500'];
        }
        $good = $change == 0 ? null : (($change > 0) === $higherIsBetter);

        return [
            'text' => ($change > 0 ? '+' : '').number_format($change, 1).'%',
            'class' => $good === null ? 'text-gray-500' : ($good ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400'),
        ];
    };
    $headers = [
        'label' => ['القناة', true],
        'orders' => ['الطلبات', true],
        'net_sales' => ['صافي المبيعات', true],
        'sales_share' => ['الحصة', true],
        'avg_order' => ['متوسط الطلب', true],
        'discount' => ['الخصومات', true],
        'charges' => ['خدمة ورسوم ومعدات', $columns['charges']],
        'net_profit' => ['مجمل الربح', true],
        'margin' => ['الهامش', true],
        'refunds' => ['المرتجعات', $columns['refunds']],
        'cancel_rate' => ['الملغي', $columns['cancelled']],
        'customers' => ['العملاء', $columns['customers']],
        'avg_per_guest' => ['الأفراد', $columns['guests']],
    ];
    $hasComparison = $comparison !== null;
@endphp
<x-filament-widgets::widget>
    <x-filament::section heading="جدول مقارنة القنوات"
        :description="$hasComparison ? 'التغير مقارنة بـ '.$comparison->label() : ($emptyComparison ? 'لا توجد مبيعات في فترة المقارنة ('.$emptyComparison->label().')' : ($columns['refunds'] ? 'صافي المبيعات = إجمالي الطلبات المكتملة بعد خصم المرتجعات' : 'صافي المبيعات = إجمالي الطلبات المكتملة'))">
        <div class="overflow-x-auto">
            <table class="w-full text-sm whitespace-nowrap" data-testid="channel-comparison-table">
                <thead>
                    <tr class="text-gray-500 border-b border-gray-200 dark:border-gray-700">
                        @foreach ($headers as $key => [$title, $visible])
                            @continue(! $visible)
                            <th class="p-2 {{ $key === 'label' ? 'text-start' : 'text-center' }}">
                                <button type="button" wire:click="sortBy('{{ $key }}')" class="inline-flex items-center gap-1 hover:text-gray-900 dark:hover:text-white">
                                    {{ $title }}
                                    @if ($sort === $key)
                                        <x-filament::icon :icon="$direction === 'asc' ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down'" class="h-4 w-4" />
                                    @endif
                                </button>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ([...$rows, $totals] as $row)
                        @php
                            $isTotal = $row['code'] === null;
                            $before = $isTotal ? $previousTotals : ($hasComparison ? ($previous[$row['code']] ?? []) : null);
                        @endphp
                        <tr @class([
                            'border-b border-gray-100 dark:border-gray-800',
                            'font-semibold bg-gray-50 dark:bg-white/5 border-t-2 border-t-gray-300 dark:border-t-gray-600' => $isTotal,
                        ]) data-testid="{{ $isTotal ? 'channel-row-total' : 'channel-row-'.$row['code'] }}">
                            <td class="p-2">
                                @if ($isTotal)
                                    {{ $row['label'] }}
                                @else
                                    <a href="{{ $ordersUrl($row['code']) }}" class="inline-flex items-center gap-2 hover:underline" title="عرض طلبات القناة">
                                        <span class="inline-block h-3 w-3 rounded-full" style="background-color: {{ $row['chart'] }}"></span>
                                        {{ $row['label'] }}
                                    </a>
                                @endif
                            </td>
                            <td class="p-2 text-center">
                                {{ number_format($row['orders']) }}
                                @if ($d = $delta($before, 'orders', $row['orders']))
                                    <div class="text-xs {{ $d['class'] }}">{{ $d['text'] }}</div>
                                @endif
                            </td>
                            <td class="p-2 text-center">
                                {{ $money($row['net_sales']) }}
                                @if ($d = $delta($before, 'net_sales', $row['net_sales']))
                                    <div class="text-xs {{ $d['class'] }}">{{ $d['text'] }}</div>
                                @endif
                            </td>
                            <td class="p-2">
                                <div class="flex items-center gap-2 min-w-28">
                                    <span class="w-12 text-end">{{ $pct($row['sales_share']) }}</span>
                                    @unless ($isTotal)
                                        <div class="h-2 flex-1 rounded bg-gray-100 dark:bg-white/10">
                                            <div class="h-2 rounded" style="width: {{ max(1, min(100, $row['sales_share'])) }}%; background-color: {{ $row['chart'] }}"></div>
                                        </div>
                                    @endunless
                                </div>
                            </td>
                            <td class="p-2 text-center">
                                {{ $money($row['avg_order']) }}
                                @if ($d = $delta($before, 'avg_order', $row['avg_order']))
                                    <div class="text-xs {{ $d['class'] }}">{{ $d['text'] }}</div>
                                @endif
                            </td>
                            <td class="p-2 text-center">
                                {{ $money($row['discount']) }}
                                <div class="text-xs text-gray-500">{{ $pct($row['discount_rate']) }} من الأصناف</div>
                            </td>
                            @if ($columns['charges'])
                                <td class="p-2 text-center">{{ $money($row['charges']) }}</td>
                            @endif
                            <td class="p-2 text-center">{{ $money($row['net_profit']) }}</td>
                            <td class="p-2 text-center">{{ $pct($row['margin']) }}</td>
                            @if ($columns['refunds'])
                                <td class="p-2 text-center">
                                    {{ $money($row['refunds']) }}
                                    <div class="text-xs text-gray-500">{{ $pct($row['refund_rate']) }} · {{ number_format($row['refunds_count']) }} مرتجع</div>
                                </td>
                            @endif
                            @if ($columns['cancelled'])
                                <td class="p-2 text-center">
                                    {{ $pct($row['cancel_rate']) }}
                                    <div class="text-xs text-gray-500">{{ number_format($row['cancelled']) }} طلب</div>
                                </td>
                            @endif
                            @if ($columns['customers'])
                                <td class="p-2 text-center">{{ number_format($row['customers']) }}</td>
                            @endif
                            @if ($columns['guests'])
                                <td class="p-2 text-center">
                                    @if ($row['guests'] > 0)
                                        {{ number_format($row['guests']) }} فرد
                                        <div class="text-xs text-gray-500">متوسط الفرد {{ $money($row['avg_per_guest']) }}</div>
                                    @else
                                        —
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
