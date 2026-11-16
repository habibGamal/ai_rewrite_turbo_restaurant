@php
    $money = fn ($value) => number_format((float) $value, 2);
    $bad = ['waste', 'stocktaking_out', 'other_out'];
@endphp
<x-filament-widgets::widget>
    <x-filament::section heading="أنواع الحركة"
        description="القيمة بتكلفة الحركة. النسبة من إجمالي نفس الاتجاه (دخول أو خروج).">
        <div class="overflow-x-auto" data-testid="inventory-movement-types">
            <table class="w-full min-w-[760px] text-sm">
                <thead>
                    <tr class="text-start text-xs text-gray-500 dark:text-gray-400">
                        <th class="px-2 py-2 text-start font-medium">نوع الحركة</th>
                        <th class="px-2 py-2 text-start font-medium">الاتجاه</th>
                        <th class="px-2 py-2 text-start font-medium">المستندات</th>
                        <th class="px-2 py-2 text-start font-medium">الأصناف</th>
                        <th class="px-2 py-2 text-start font-medium">القيمة (ج.م)</th>
                        <th class="px-2 py-2 text-start font-medium">النسبة</th>
                        @if ($comparing)
                            <th class="px-2 py-2 text-start font-medium">التغير عن {{ $comparisonWord }}</th>
                        @endif
                        <th class="px-2 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($rows as $row)
                        @php($active = in_array($row['key'], $selectedBuckets, true))
                        <tr @class(['bg-primary-50 dark:bg-primary-500/10' => $active])>
                            <td class="px-2 py-2.5 font-semibold text-gray-950 dark:text-white">{{ $row['label'] }}</td>
                            <td class="px-2 py-2.5">
                                <x-filament::badge :color="$row['direction'] === 'in' ? 'success' : 'warning'" size="sm">
                                    {{ $row['direction'] === 'in' ? 'دخول' : 'خروج' }}
                                </x-filament::badge>
                            </td>
                            <td class="px-2 py-2.5 tabular-nums">{{ number_format($row['documents']) }}</td>
                            <td class="px-2 py-2.5 tabular-nums">{{ number_format($row['items']) }}</td>
                            <td class="px-2 py-2.5 font-semibold tabular-nums">{{ $money($row['value']) }}</td>
                            <td class="px-2 py-2.5">
                                <div class="flex min-w-[120px] items-center gap-2">
                                    <div class="h-1.5 flex-1 overflow-hidden rounded bg-gray-100 dark:bg-white/10">
                                        <div class="h-full {{ $row['direction'] === 'in' ? 'bg-teal-600' : 'bg-orange-600' }}" style="width: {{ $row['share'] }}%"></div>
                                    </div>
                                    <span class="w-12 text-xs tabular-nums text-gray-500">{{ number_format($row['share'], 1) }}%</span>
                                </div>
                            </td>
                            @if ($comparing)
                                <td class="px-2 py-2.5 text-xs font-semibold tabular-nums">
                                    @if ($row['change'] === null)
                                        <span class="text-gray-400">—</span>
                                    @else
                                        @php($worse = in_array($row['key'], $bad, true) && $row['change'] > 0)
                                        <span @class(['text-danger-600 dark:text-danger-400' => $worse, 'text-gray-600 dark:text-gray-300' => ! $worse])>
                                            {{ $row['change'] > 0 ? '▲' : ($row['change'] < 0 ? '▼' : '') }} {{ number_format(abs($row['change']), 1) }}%
                                        </span>
                                    @endif
                                </td>
                            @endif
                            <td class="px-2 py-2.5 text-end">
                                @if ($row['movements'] > 0)
                                    <x-filament::link tag="button" wire:click="select('{{ $row['key'] }}')" size="sm">
                                        {{ $active ? 'إخفاء المستندات' : 'المستندات' }}
                                    </x-filament::link>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    @if ($selectedLabel !== null)
        <x-filament::section class="mt-4" heading="مستندات: {{ $selectedLabel }}"
            description="أكبر 50 مستند بالقيمة في نفس الفترة والفلاتر. رقم المستند يفتحه.">
            @if ($documents === [])
                <div class="py-6 text-center text-sm text-gray-500">مفيش مستندات للنوع ده في الفترة دي.</div>
            @else
                <div class="overflow-x-auto" data-testid="inventory-movement-documents">
                    <table class="w-full min-w-[680px] text-sm">
                        <thead>
                            <tr class="text-xs text-gray-500 dark:text-gray-400">
                                <th class="px-2 py-2 text-start font-medium">المستند</th>
                                <th class="px-2 py-2 text-start font-medium">التاريخ</th>
                                <th class="px-2 py-2 text-start font-medium">المخزن</th>
                                <th class="px-2 py-2 text-start font-medium">المستخدم</th>
                                <th class="px-2 py-2 text-start font-medium">الأصناف</th>
                                <th class="px-2 py-2 text-start font-medium">القيمة (ج.م)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($documents as $document)
                                <tr>
                                    <td class="px-2 py-2">
                                        <span class="text-gray-600 dark:text-gray-300">{{ $document['type'] }}</span>
                                        @if ($document['url'])
                                            <x-filament::link :href="$document['url']" target="_blank">#{{ $document['number'] }}</x-filament::link>
                                        @else
                                            #{{ $document['number'] }}
                                        @endif
                                    </td>
                                    <td class="px-2 py-2 tabular-nums">{{ $document['date'] }}</td>
                                    <td class="px-2 py-2">{{ $document['warehouse'] ?? '—' }}</td>
                                    <td class="px-2 py-2">{{ $document['user'] ?? '—' }}</td>
                                    <td class="px-2 py-2 tabular-nums">{{ number_format($document['items']) }}</td>
                                    <td class="px-2 py-2 font-semibold tabular-nums">{{ $money($document['value']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
