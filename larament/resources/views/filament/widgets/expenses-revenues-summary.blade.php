@php($money = fn ($value) => number_format((float) $value, 2))
<x-filament-widgets::widget>
    <x-filament::section heading="ملخص المصروفات والإيرادات">
        <div class="overflow-x-auto">
            <table class="w-full text-sm" data-testid="expenses-revenues-summary">
                <thead>
                    <tr class="text-gray-500 border-b border-gray-200 dark:border-gray-700">
                        <th class="p-2 text-start">البند</th>
                        <th class="p-2">نقدي</th>
                        <th class="p-2">فيزا</th>
                        <th class="p-2">المبلغ (ج.م)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="bg-gray-50 dark:bg-white/5">
                        <td class="p-2 font-semibold" colspan="4">الإيرادات</td>
                    </tr>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <td class="p-2 font-medium">
                            إيرادات الطلبات
                            <span class="text-xs text-gray-500">({{ $summary['orders']['count'] }} طلب مكتمل، بعد خصم المرتجعات)</span>
                        </td>
                        <td class="p-2 text-center">—</td>
                        <td class="p-2 text-center">—</td>
                        <td class="p-2 text-center font-semibold">{{ $money($summary['orders']['net']) }}</td>
                    </tr>
                    @foreach ($summary['orders']['parts'] as $label => $amount)
                        <tr class="border-b border-gray-100 dark:border-gray-800 text-gray-600 dark:text-gray-400">
                            <td class="p-2 ps-8">{{ $label }}</td>
                            <td class="p-2"></td>
                            <td class="p-2"></td>
                            <td class="p-2 text-center">{{ $money($amount) }}</td>
                        </tr>
                    @endforeach
                    @if ($summary['orders']['refunds'] != 0)
                        <tr class="border-b border-gray-100 dark:border-gray-800 text-gray-600 dark:text-gray-400">
                            <td class="p-2 ps-8">المرتجعات</td>
                            <td class="p-2"></td>
                            <td class="p-2"></td>
                            <td class="p-2 text-center">{{ $money(-$summary['orders']['refunds']) }}</td>
                        </tr>
                    @endif
                    @foreach ($summary['revenue_types'] as $type)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="p-2 font-medium">{{ $type['name'] }} <span class="text-xs text-gray-500">({{ $type['count'] }})</span></td>
                            <td class="p-2 text-center">{{ $money($type['cash']) }}</td>
                            <td class="p-2 text-center">{{ $money($type['card']) }}</td>
                            <td class="p-2 text-center font-semibold">{{ $money($type['total']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="border-b border-gray-200 dark:border-gray-700 font-semibold text-success-600 dark:text-success-400">
                        <td class="p-2">إجمالي الإيرادات</td>
                        <td class="p-2"></td>
                        <td class="p-2"></td>
                        <td class="p-2 text-center">{{ $money($summary['total_revenues']) }}</td>
                    </tr>
                    <tr class="border-b border-gray-200 dark:border-gray-700 font-semibold text-danger-600 dark:text-danger-400">
                        <td class="p-2">إجمالي المصروفات</td>
                        <td class="p-2"></td>
                        <td class="p-2"></td>
                        <td class="p-2 text-center">{{ $money($summary['expenses']) }}</td>
                    </tr>
                    <tr class="font-bold text-base">
                        <td class="p-2">الصافي (الإيرادات − المصروفات)</td>
                        <td class="p-2"></td>
                        <td class="p-2"></td>
                        <td class="p-2 text-center" data-testid="expenses-revenues-net">{{ $money($summary['net']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
