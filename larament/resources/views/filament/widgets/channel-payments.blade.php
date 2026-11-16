<x-filament-widgets::widget>
    <x-filament::section heading="طرق الدفع لكل قناة" description="المدفوعات المسجلة على الطلبات المكتملة في الفترة">
        @if ($rows === [])
            <p class="text-sm text-gray-500">لا توجد مدفوعات مسجلة في الفترة.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm whitespace-nowrap" data-testid="channel-payments">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500 dark:border-gray-700">
                            <th class="p-2 text-start">القناة</th>
                            @foreach ($methods as $method => $label)
                                <th class="p-2 text-center">
                                    <span class="me-1 inline-block h-2 w-2 rounded-full" style="background-color: {{ $colors[$method] ?? '#64748b' }}"></span>{{ $label }}
                                </th>
                            @endforeach
                            <th class="p-2 text-center">الإجمالي</th>
                            <th class="p-2 text-start min-w-48">التوزيع</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="p-2">
                                    <span class="me-1 inline-block h-3 w-3 rounded-full align-middle" style="background-color: {{ $row['chart'] }}"></span>
                                    {{ $row['label'] }}
                                </td>
                                @foreach ($methods as $method => $label)
                                    @php($amount = $row['methods'][$method] ?? 0)
                                    <td class="p-2 text-center">
                                        {{ number_format($amount, 2) }}
                                        <div class="text-xs text-gray-500">{{ $row['total'] != 0 ? number_format($amount / $row['total'] * 100, 1) : '0.0' }}%</div>
                                    </td>
                                @endforeach
                                <td class="p-2 text-center font-semibold">{{ number_format($row['total'], 2) }}</td>
                                <td class="p-2">
                                    <div class="flex h-3 overflow-hidden rounded bg-gray-100 dark:bg-white/10">
                                        @foreach ($methods as $method => $label)
                                            @php($amount = $row['methods'][$method] ?? 0)
                                            @if ($row['total'] > 0 && $amount > 0)
                                                <div style="width: {{ $amount / $row['total'] * 100 }}%; background-color: {{ $colors[$method] ?? '#64748b' }}" title="{{ $label }}"></div>
                                            @endif
                                        @endforeach
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
