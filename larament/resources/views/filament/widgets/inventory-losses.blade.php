@php
    use App\Filament\Pages\Reports\ItemLedgerReport;
    $money = fn ($value) => number_format((float) $value, 2);
    $qty = fn ($value) => number_format((float) $value, 3);
    $maxLoss = max(1, ...array_map(fn ($row) => abs($row['loss']), $trend ?: [['loss' => 0]]));
    $th = 'px-2 py-2 text-start font-medium';
    $td = 'px-2 py-2 tabular-nums';
@endphp
<x-filament-widgets::widget>
    <div class="grid gap-4">
        <x-filament::section heading="الفاقد على مدار الفترة"
            description="الفاقد = هالك + عجز جرد − زيادة جرد. النسبة من (الاستهلاك + الصرف + الفاقد).">
            <div class="overflow-x-auto" data-testid="inventory-loss-trend">
                <table class="w-full min-w-[720px] text-sm">
                    <thead>
                        <tr class="text-xs text-gray-500 dark:text-gray-400">
                            <th class="{{ $th }}">الفترة</th>
                            <th class="{{ $th }}">هالك</th>
                            <th class="{{ $th }}">عجز جرد</th>
                            <th class="{{ $th }}">زيادة جرد</th>
                            <th class="{{ $th }}">الفاقد (ج.م)</th>
                            <th class="{{ $th }}">نسبة الفاقد</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($trend as $row)
                            <tr>
                                <td class="{{ $td }} font-medium">{{ $row['label'] }}</td>
                                <td class="{{ $td }}">{{ $money($row['waste']) }}</td>
                                <td class="{{ $td }}">{{ $money($row['shortage']) }}</td>
                                <td class="{{ $td }}">{{ $money($row['surplus']) }}</td>
                                <td class="{{ $td }}">
                                    <div class="flex min-w-[160px] items-center gap-2">
                                        <div class="h-1.5 flex-1 overflow-hidden rounded bg-gray-100 dark:bg-white/10">
                                            <div class="h-full bg-orange-600" style="width: {{ max(0, $row['loss']) * 100 / $maxLoss }}%"></div>
                                        </div>
                                        <span class="w-20 font-semibold">{{ $money($row['loss']) }}</span>
                                    </div>
                                </td>
                                <td class="{{ $td }}">
                                    <x-filament::badge size="sm" :color="$row['rate'] >= 7 ? 'danger' : ($row['rate'] >= 3 ? 'warning' : 'gray')">
                                        {{ number_format($row['rate'], 1) }}%
                                    </x-filament::badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section heading="أكتر الأصناف فاقدًا"
            description="{{ $restaurant ? 'الفرق عن النظري = صافي فرق الجرد ÷ الاستهلاك المحسوب من الوصفات.' : 'أكبر 15 صنف بقيمة الفاقد.' }} اضغط على الصنف يفتح كشف حركته.">
            @if ($items === [])
                <div class="py-6 text-center text-sm text-gray-500">مفيش فاقد في الفترة دي.</div>
            @else
                <div class="overflow-x-auto" data-testid="inventory-loss-items">
                    <table class="w-full min-w-[820px] text-sm">
                        <thead>
                            <tr class="text-xs text-gray-500 dark:text-gray-400">
                                <th class="{{ $th }}">الصنف</th>
                                <th class="{{ $th }}">كمية الهالك</th>
                                <th class="{{ $th }}">صافي عجز الجرد</th>
                                <th class="{{ $th }}">الفاقد (ج.م)</th>
                                <th class="{{ $th }}">من إجمالي الفاقد</th>
                                <th class="{{ $th }}">نسبة الفاقد</th>
                                @if ($restaurant)
                                    <th class="{{ $th }}">الفرق عن النظري</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($items as $item)
                                <tr>
                                    <td class="px-2 py-2 font-semibold">
                                        <x-filament::link :href="ItemLedgerReport::getUrl(['filters' => $ledgerFilters($item['product_id'])])">{{ $item['name'] }}</x-filament::link>
                                    </td>
                                    <td class="{{ $td }}">{{ $qty($item['waste_qty']) }}</td>
                                    <td class="{{ $td }}">{{ $qty($item['shortage_qty']) }}</td>
                                    <td class="{{ $td }} font-semibold">{{ $money($item['loss']) }}</td>
                                    <td class="{{ $td }}">{{ number_format($item['share'], 1) }}%</td>
                                    <td class="{{ $td }}">{{ number_format($item['rate'], 1) }}%</td>
                                    @if ($restaurant)
                                        <td class="{{ $td }}">
                                            @if ($item['against_theory'] === null)
                                                <span class="text-gray-400">—</span>
                                            @else
                                                <span @class(['text-danger-600 dark:text-danger-400' => $item['against_theory'] >= 5])>{{ number_format($item['against_theory'], 1) }}%</span>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section heading="عجز متكرر" description="أصناف طلع فيها عجز في جردين أو أكتر في نفس الفترة.">
            @if ($repeated === [])
                <div class="py-6 text-center text-sm text-gray-500">مفيش صنف عجزه اتكرر.</div>
            @else
                <div class="overflow-x-auto" data-testid="inventory-repeated-shortages">
                    <table class="w-full min-w-[560px] text-sm">
                        <thead>
                            <tr class="text-xs text-gray-500 dark:text-gray-400">
                                <th class="{{ $th }}">الصنف</th>
                                <th class="{{ $th }}">مرات العجز</th>
                                <th class="{{ $th }}">الكمية</th>
                                <th class="{{ $th }}">القيمة (ج.م)</th>
                                <th class="{{ $th }}">آخر جرد</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($repeated as $row)
                                <tr>
                                    <td class="px-2 py-2 font-semibold">
                                        <x-filament::link :href="ItemLedgerReport::getUrl(['filters' => $ledgerFilters($row['product_id'])])">{{ $row['name'] }}</x-filament::link>
                                    </td>
                                    <td class="{{ $td }}"><x-filament::badge size="sm" color="danger">{{ $row['counts'] }}</x-filament::badge></td>
                                    <td class="{{ $td }}">{{ $qty($row['quantity']) }}</td>
                                    <td class="{{ $td }} font-semibold">{{ $money($row['value']) }}</td>
                                    <td class="{{ $td }}">{{ $row['last'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        @if ($slowMoving !== null)
            <x-filament::section heading="بضاعة راكدة" description="أصناف ليها رصيد آخر الفترة ومتباعتش من {{ $slowDays }} يوم أو أكتر.">
                @if ($slowMoving === [])
                    <div class="py-6 text-center text-sm text-gray-500">مفيش بضاعة راكدة.</div>
                @else
                    <div class="overflow-x-auto" data-testid="inventory-slow-moving">
                        <table class="w-full min-w-[560px] text-sm">
                            <thead>
                                <tr class="text-xs text-gray-500 dark:text-gray-400">
                                    <th class="{{ $th }}">الصنف</th>
                                    <th class="{{ $th }}">الرصيد</th>
                                    <th class="{{ $th }}">القيمة (ج.م)</th>
                                    <th class="{{ $th }}">آخر بيع</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                @foreach ($slowMoving as $row)
                                    <tr>
                                        <td class="px-2 py-2 font-semibold">
                                            <x-filament::link :href="ItemLedgerReport::getUrl(['filters' => $ledgerFilters($row['product_id'])])">{{ $row['name'] }}</x-filament::link>
                                        </td>
                                        <td class="{{ $td }}">{{ $qty($row['quantity']) }}</td>
                                        <td class="{{ $td }} font-semibold">{{ $money($row['value']) }}</td>
                                        <td class="{{ $td }}">{{ $row['last_sale'] ? $row['last_sale'].' (من '.$row['days'].' يوم)' : 'متباعش خالص' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        @endif
    </div>
</x-filament-widgets::widget>
