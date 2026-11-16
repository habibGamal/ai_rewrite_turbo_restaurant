@php
    $money = fn ($value) => number_format((float) $value, 2);
    $th = 'px-2 py-2 text-start font-medium';
    $td = 'px-2 py-2 tabular-nums';
@endphp
<x-filament-widgets::widget>
    <div class="grid gap-4">
        <x-filament::section heading="المشتريات حسب المورد" description="بتكلفة حركات الشراء ومرتجعاته في الفترة.">
            @if ($suppliers === [])
                <div class="py-6 text-center text-sm text-gray-500">مفيش مشتريات في الفترة دي.</div>
            @else
                <div class="overflow-x-auto" data-testid="inventory-suppliers">
                    <table class="w-full min-w-[720px] text-sm">
                        <thead>
                            <tr class="text-xs text-gray-500 dark:text-gray-400">
                                <th class="{{ $th }}">المورد</th>
                                <th class="{{ $th }}">الفواتير</th>
                                <th class="{{ $th }}">الأصناف</th>
                                <th class="{{ $th }}">المشتريات (ج.م)</th>
                                <th class="{{ $th }}">المرتجع (ج.م)</th>
                                <th class="{{ $th }}">الصافي (ج.م)</th>
                                <th class="{{ $th }}">النسبة</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($suppliers as $row)
                                <tr>
                                    <td class="px-2 py-2 font-semibold">{{ $row['supplier'] }}</td>
                                    <td class="{{ $td }}">{{ number_format($row['invoices']) }}</td>
                                    <td class="{{ $td }}">{{ number_format($row['items']) }}</td>
                                    <td class="{{ $td }}">{{ $money($row['purchases']) }}</td>
                                    <td class="{{ $td }}">{{ $money($row['returns']) }}</td>
                                    <td class="{{ $td }} font-semibold">{{ $money($row['net']) }}</td>
                                    <td class="{{ $td }}">
                                        <div class="flex min-w-[120px] items-center gap-2">
                                            <div class="h-1.5 flex-1 overflow-hidden rounded bg-gray-100 dark:bg-white/10">
                                                <div class="h-full bg-teal-600" style="width: {{ $row['share'] }}%"></div>
                                            </div>
                                            <span class="w-12 text-xs text-gray-500">{{ number_format($row['share'], 1) }}%</span>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section heading="تغيرات التكلفة"
            description="أول وآخر تكلفة للصنف في الفترة. الأثر = فرق التكلفة × الكمية المشتراة في الفترة.">
            <x-slot name="afterHeader">
                <x-filament::link :href="$costHistoryUrl" icon="heroicon-m-arrow-top-right-on-square" size="sm">سجل تغيرات التكلفة</x-filament::link>
            </x-slot>
            @if ($costChanges === [])
                <div class="py-6 text-center text-sm text-gray-500">مفيش تغير في التكلفة في الفترة دي.</div>
            @else
                <div class="overflow-x-auto" data-testid="inventory-cost-changes">
                    <table class="w-full min-w-[720px] text-sm">
                        <thead>
                            <tr class="text-xs text-gray-500 dark:text-gray-400">
                                <th class="{{ $th }}">الصنف</th>
                                <th class="{{ $th }}">مرات التغير</th>
                                <th class="{{ $th }}">من</th>
                                <th class="{{ $th }}">إلى</th>
                                <th class="{{ $th }}">التغير</th>
                                <th class="{{ $th }}">الكمية المشتراة</th>
                                <th class="{{ $th }}">الأثر (ج.م)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($costChanges as $row)
                                <tr>
                                    <td class="px-2 py-2 font-semibold">{{ $row['name'] }}</td>
                                    <td class="{{ $td }}">{{ $row['changes'] }}</td>
                                    <td class="{{ $td }}">{{ $money($row['old_cost']) }}</td>
                                    <td class="{{ $td }}">{{ $money($row['new_cost']) }}</td>
                                    <td class="{{ $td }}">
                                        @if ($row['change'] === null)
                                            <span class="text-gray-400">—</span>
                                        @else
                                            <span @class(['text-danger-600 dark:text-danger-400' => $row['change'] > 0, 'text-success-600 dark:text-success-400' => $row['change'] < 0])>
                                                {{ $row['change'] > 0 ? '▲' : '▼' }} {{ number_format(abs($row['change']), 1) }}%
                                            </span>
                                        @endif
                                    </td>
                                    <td class="{{ $td }}">{{ number_format($row['purchased_qty'], 3) }}</td>
                                    <td class="{{ $td }} font-semibold">{{ $row['impact'] > 0 ? '+' : '' }}{{ $money($row['impact']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
