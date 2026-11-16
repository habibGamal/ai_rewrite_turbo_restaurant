@php
    $max = collect($products)->max($by) ?: 1;
    $color = $tabs[$channel]['chart'] ?? '#2563eb';
@endphp
<x-filament-widgets::widget>
    <x-filament::section heading="أكثر الأصناف مبيعاً في كل قناة" description="قيمة الأصناف قبل خصم الطلب">
        <x-slot name="afterHeader">
            <select wire:model.live="by" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                <option value="sales">بالقيمة</option>
                <option value="quantity">بالكمية</option>
            </select>
        </x-slot>

        <div class="mb-4 flex flex-wrap gap-2">
            @foreach ($tabs as $code => $tab)
                <button type="button" wire:click="$set('channel', '{{ $code }}')"
                    @class([
                        'rounded-full px-3 py-1 text-sm border transition',
                        'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-400' => $code === $channel,
                        'border-gray-200 text-gray-600 hover:bg-gray-50 dark:border-white/10 dark:text-gray-300 dark:hover:bg-white/5' => $code !== $channel,
                    ])>
                    <span class="me-1 inline-block h-2 w-2 rounded-full" style="background-color: {{ $tab['chart'] }}"></span>
                    {{ $tab['label'] }}
                </button>
            @endforeach
        </div>

        @if ($products === [])
            <p class="text-sm text-gray-500">لا توجد أصناف مباعة في الفترة.</p>
        @else
            <table class="w-full text-sm" data-testid="channel-products">
                <thead>
                    <tr class="border-b border-gray-200 text-gray-500 dark:border-gray-700">
                        <th class="w-8 p-2 text-start">#</th>
                        <th class="p-2 text-start">الصنف</th>
                        <th class="p-2 text-center">الكمية</th>
                        <th class="p-2 text-center">القيمة (ج.م)</th>
                        <th class="p-2 text-start">من مبيعات القناة</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($products as $i => $product)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="p-2 text-gray-500">{{ $i + 1 }}</td>
                            <td class="p-2 font-medium">{{ $product['name'] }}</td>
                            <td class="p-2 text-center">{{ rtrim(rtrim(number_format($product['quantity'], 3), '0'), '.') }}</td>
                            <td class="p-2 text-center">{{ number_format($product['sales'], 2) }}</td>
                            <td class="p-2">
                                <div class="flex items-center gap-2">
                                    <div class="h-2 w-40 rounded bg-gray-100 dark:bg-white/10">
                                        <div class="h-2 rounded" style="width: {{ max(1, round($product[$by] / $max * 100)) }}%; background-color: {{ $color }}"></div>
                                    </div>
                                    <span class="text-gray-500">{{ number_format($product['share'], 1) }}%</span>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
