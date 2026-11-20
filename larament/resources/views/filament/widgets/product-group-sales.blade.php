<x-filament-widgets::widget>
    <x-filament::section heading="مبيعات الموديلات" description="كل مقاسات وألوان الموديل في صف واحد">
        @if (empty($groups))
            <p class="text-sm text-gray-500">لا توجد مبيعات لموديلات في هذه الفترة.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm" data-testid="product-group-sales">
                    <thead>
                        <tr class="text-gray-500 border-b border-gray-200 dark:border-gray-700">
                            <th class="p-2 text-start">الموديل</th>
                            <th class="p-2">الكمية</th>
                            <th class="p-2">المبيعات</th>
                            <th class="p-2">الربح</th>
                            <th class="p-2">أصناف مباعة</th>
                            <th class="p-2 text-start">الأكثر مبيعاً</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($groups as $group)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="p-2 font-medium">{{ $group['group_name'] }}</td>
                                <td class="p-2 text-center">{{ rtrim(rtrim(number_format($group['total_quantity'], 3, '.', ''), '0'), '.') }}</td>
                                <td class="p-2 text-center">{{ number_format($group['total_sales'], 2) }}</td>
                                <td class="p-2 text-center">{{ number_format($group['total_profit'], 2) }}</td>
                                <td class="p-2 text-center">{{ $group['variants_sold'] }} / {{ $group['variants_total'] }}</td>
                                <td class="p-2">{{ $group['top_variant'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
