@php
    $money = fn ($value) => number_format((float) $value, 2);
    $th = 'px-2 py-2 text-start font-medium';
    $td = 'px-2 py-2 tabular-nums';
@endphp
<x-filament-widgets::widget>
    <div class="grid gap-4">
        <x-filament::section heading="المخازن"
            description="كل المخازن جنب بعض بنفس فلتر الأصناف (فلتر المخزن مش بيتطبق هنا). الوارد والصادر من غير التحويلات والهالك والجرد.">
            <div class="overflow-x-auto" data-testid="inventory-warehouses">
                <table class="w-full min-w-[820px] text-sm">
                    <thead>
                        <tr class="text-xs text-gray-500 dark:text-gray-400">
                            <th class="{{ $th }}">المخزن</th>
                            <th class="{{ $th }}">أول المدة</th>
                            <th class="{{ $th }}">وارد</th>
                            <th class="{{ $th }}">صادر</th>
                            <th class="{{ $th }}">تحويل وارد</th>
                            <th class="{{ $th }}">تحويل صادر</th>
                            <th class="{{ $th }}">الفاقد</th>
                            <th class="{{ $th }}">آخر المدة</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($warehouses as $row)
                            <tr>
                                <td class="px-2 py-2 font-semibold">{{ $row['warehouse'] }}</td>
                                <td class="{{ $td }}">{{ $money($row['opening']) }}</td>
                                <td class="{{ $td }} text-success-600 dark:text-success-400">{{ $money($row['in']) }}</td>
                                <td class="{{ $td }} text-warning-600 dark:text-warning-400">{{ $money($row['out']) }}</td>
                                <td class="{{ $td }}">{{ $money($row['transfer_in']) }}</td>
                                <td class="{{ $td }}">{{ $money($row['transfer_out']) }}</td>
                                <td @class([$td, 'text-danger-600 dark:text-danger-400' => $row['loss'] > 0])>{{ $money($row['loss']) }}</td>
                                <td class="{{ $td }} font-semibold">{{ $money($row['closing']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <div class="grid gap-4 lg:grid-cols-2">
            <x-filament::section heading="التحويلات بين المخازن" description="أذون التحويل المؤكدة في الفترة.">
                @if ($transfers === [])
                    <div class="py-6 text-center text-sm text-gray-500">مفيش تحويلات في الفترة دي.</div>
                @else
                    <table class="w-full text-sm" data-testid="inventory-transfer-matrix">
                        <thead>
                            <tr class="text-xs text-gray-500 dark:text-gray-400">
                                <th class="{{ $th }}">من ← إلى</th>
                                <th class="{{ $th }}">الأذون</th>
                                <th class="{{ $th }}">الأصناف</th>
                                <th class="{{ $th }}">القيمة (ج.م)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($transfers as $row)
                                <tr>
                                    <td class="px-2 py-2 font-semibold">{{ $row['from'] }} ← {{ $row['to'] }}</td>
                                    <td class="{{ $td }}">{{ $row['transfers'] }}</td>
                                    <td class="{{ $td }}">{{ $row['items'] }}</td>
                                    <td class="{{ $td }} font-semibold">{{ $money($row['value']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-filament::section>

            <x-filament::section heading="الصرف حسب الجهة" description="أذون الصرف المؤكدة مجمعة بالجهة المكتوبة عليها.">
                @if ($issues === [])
                    <div class="py-6 text-center text-sm text-gray-500">مفيش صرف في الفترة دي.</div>
                @else
                    <table class="w-full text-sm" data-testid="inventory-issue-destinations">
                        <thead>
                            <tr class="text-xs text-gray-500 dark:text-gray-400">
                                <th class="{{ $th }}">الجهة</th>
                                <th class="{{ $th }}">الأذون</th>
                                <th class="{{ $th }}">القيمة (ج.م)</th>
                                <th class="{{ $th }}">النسبة</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($issues as $row)
                                <tr>
                                    <td class="px-2 py-2 font-semibold">{{ $row['destination'] }}</td>
                                    <td class="{{ $td }}">{{ $row['issues'] }}</td>
                                    <td class="{{ $td }} font-semibold">{{ $money($row['value']) }}</td>
                                    <td class="{{ $td }}">{{ number_format($row['share'], 1) }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-filament::section>
        </div>
    </div>
</x-filament-widgets::widget>
