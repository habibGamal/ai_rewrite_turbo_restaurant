@php
    $hourLabel = fn (int $hour) => $hour === 0 ? '12ص' : ($hour < 12 ? $hour.'ص' : ($hour === 12 ? '12م' : ($hour - 12).'م'));
@endphp
<x-filament-widgets::widget>
    <x-filament::section heading="الساعات × أيام الأسبوع" description="كل ما اللون أغمق كل ما الطلبات أكتر">
        <x-slot name="afterHeader">
            <div class="flex flex-wrap gap-2">
                <select wire:model.live="channel" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                    <option value="">كل القنوات</option>
                    @foreach ($channels as $code => $label)
                        <option value="{{ $code }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="metric" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                    <option value="orders">الطلبات</option>
                    <option value="sales">المبيعات</option>
                </select>
            </div>
        </x-slot>

        @if ($hours === [])
            <p class="text-sm text-gray-500">لا توجد طلبات لهذه القناة في الفترة.</p>
        @else
            <div class="overflow-x-auto" data-testid="channel-heatmap">
                <table class="w-full border-separate text-xs" style="border-spacing: 2px">
                    <thead>
                        <tr>
                            <th></th>
                            @foreach ($hours as $hour)
                                <th class="px-1 font-normal text-gray-500">{{ $hourLabel($hour) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($days as $weekday => $dayName)
                            <tr>
                                <th class="pe-2 text-start font-normal text-gray-500 whitespace-nowrap">{{ $dayName }}</th>
                                @foreach ($hours as $hour)
                                    @php
                                        $cell = $grid[$weekday][$hour];
                                        $value = $cell[$metric];
                                        $alpha = $max > 0 && $value > 0 ? 0.12 + 0.88 * $value / $max : 0;
                                    @endphp
                                    <td class="h-8 min-w-8 rounded text-center {{ $alpha > 0.55 ? 'text-white' : 'text-gray-700 dark:text-gray-300' }}"
                                        style="background-color: {{ $alpha > 0 ? 'rgba(37, 99, 235, '.round($alpha, 2).')' : 'rgba(148, 163, 184, 0.12)' }}"
                                        title="{{ $dayName }} {{ $hourLabel($hour) }}: {{ number_format($cell['orders']) }} طلب · {{ number_format($cell['sales'], 2) }} ج.م">
                                        {{ $value > 0 ? ($metric === 'orders' ? number_format($value) : number_format($value / 1000, 1).'k') : '' }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
