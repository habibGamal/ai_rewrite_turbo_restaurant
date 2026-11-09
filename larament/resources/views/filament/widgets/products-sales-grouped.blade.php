<x-filament-widgets::widget>
    <x-filament::section>
        <h3 class="mb-4 text-base font-semibold text-gray-950 dark:text-white">
            المبيعات حسب التصنيف الرئيسي والفرعي
        </h3>

        @forelse ($groups as $group)
            <div class="mb-6 overflow-hidden rounded-xl border border-gray-200 dark:border-white/10">
                <div class="flex items-center justify-between gap-4 bg-gray-50 px-4 py-3 dark:bg-white/5">
                    <div>
                        <div class="text-sm font-semibold text-gray-950 dark:text-white">{{ $group['main_category_name'] }}</div>
                        <div class="text-xs text-gray-500">التصنيف الرئيسي</div>
                    </div>
                    <div class="text-end text-sm">
                        <div>{{ number_format($group['total_quantity'], 3) }}</div>
                        <div class="font-semibold">{{ number_format($group['total_sales'], 2) }} ج.م</div>
                        <div class="text-xs text-gray-500">ربح {{ number_format($group['total_profit'], 2) }} ج.م</div>
                    </div>
                </div>

                @foreach ($group['sub_categories'] as $sub)
                    <div class="border-t border-gray-200 px-4 py-3 dark:border-white/10">
                        <div class="mb-2 flex items-center justify-between gap-4">
                            <div class="text-sm font-medium text-gray-800 dark:text-gray-100">
                                {{ $sub['sub_category_name'] ?? 'أصناف التصنيف الرئيسي' }}
                            </div>
                            <div class="text-xs text-gray-500">
                                {{ number_format($sub['total_quantity'], 3) }} · {{ number_format($sub['total_sales'], 2) }} ج.م · ربح {{ number_format($sub['total_profit'], 2) }} ج.م
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-start text-xs text-gray-500">
                                        <th class="py-1 text-start font-medium">المنتج</th>
                                        <th class="py-1 text-end font-medium">الكمية</th>
                                        <th class="py-1 text-end font-medium">المبيعات</th>
                                        <th class="py-1 text-end font-medium">الربح</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($sub['products'] as $product)
                                        <tr class="border-t border-gray-100 dark:border-white/5">
                                            <td class="py-1">{{ $product['name'] }}</td>
                                            <td class="py-1 text-end">{{ number_format($product['total_quantity'], 3) }}</td>
                                            <td class="py-1 text-end">{{ number_format($product['total_sales'], 2) }} ج.م</td>
                                            <td class="py-1 text-end">{{ number_format($product['total_profit'], 2) }} ج.م</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            </div>
        @empty
            <p class="text-sm text-gray-500">لا توجد مبيعات للتصنيف المحدد</p>
        @endforelse
    </x-filament::section>
</x-filament-widgets::widget>
