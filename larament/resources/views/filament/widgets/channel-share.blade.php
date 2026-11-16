@php
    $pct = fn ($value) => number_format((float) $value, 1).'%';
    $bars = [
        ['الطلبات', 'orders_share'],
        ['صافي المبيعات', 'sales_share'],
    ];
@endphp
<x-filament-widgets::widget>
    <x-filament::section heading="الحصة: الطلبات مقابل المبيعات"
        description="القناة اللي حصتها من المبيعات أكبر من حصتها من الطلبات طلباتها أغلى من المتوسط">
        <div class="space-y-3" data-testid="channel-share">
            @foreach ($bars as [$title, $key])
                <div class="flex items-center gap-3">
                    <div class="w-28 shrink-0 text-sm text-gray-500">{{ $title }}</div>
                    <div class="flex h-7 flex-1 overflow-hidden rounded-md bg-gray-100 dark:bg-white/10">
                        @foreach ($rows as $row)
                            @continue($row[$key] <= 0)
                            <div class="flex items-center justify-center overflow-hidden text-xs font-medium text-white"
                                style="width: {{ $row[$key] }}%; background-color: {{ $row['chart'] }}"
                                title="{{ $row['label'] }}: {{ $pct($row[$key]) }}">
                                @if ($row[$key] >= 6)
                                    {{ $pct($row[$key]) }}
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach

            <div class="flex flex-wrap gap-x-5 gap-y-2 pt-2 text-sm">
                @foreach ($rows as $row)
                    <div class="flex items-center gap-2">
                        <span class="inline-block h-3 w-3 rounded-sm" style="background-color: {{ $row['chart'] }}"></span>
                        <span>{{ $row['label'] }}</span>
                        <span class="text-gray-500">طلبات {{ $pct($row['orders_share']) }} · مبيعات {{ $pct($row['sales_share']) }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
