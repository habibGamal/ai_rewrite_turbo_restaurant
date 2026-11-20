@php
    $icons = ['danger' => 'heroicon-o-exclamation-triangle', 'warning' => 'heroicon-o-exclamation-circle', 'success' => 'heroicon-o-check-circle'];
    $th = 'px-2 py-1.5 text-start font-medium';
@endphp
<x-filament-widgets::widget>
    <div class="grid gap-4 lg:grid-cols-2" data-testid="inventory-alerts">
        @foreach ($alerts as $alert)
            <x-filament::section :icon="$icons[$alert['level']]" :icon-color="$alert['level']" :heading="$alert['title']" :description="$alert['hint']">
                <x-slot name="afterHeader">
                    <x-filament::badge :color="$alert['level']">{{ $alert['count'] > 0 ? number_format($alert['count']) : 'تمام' }}</x-filament::badge>
                </x-slot>
                @if ($alert['rows'] === [])
                    <div class="text-sm text-gray-500">مفيش حاجة محتاجة مراجعة.</div>
                @else
                    @php($columns = array_values(array_diff(array_keys($alert['rows'][0]), ['url'])))
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-xs text-gray-500 dark:text-gray-400">
                                    @foreach ($columns as $column)
                                        <th class="{{ $th }}">{{ $column }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                @foreach ($alert['rows'] as $row)
                                    <tr>
                                        @foreach ($columns as $index => $column)
                                            <td class="px-2 py-1.5 tabular-nums {{ $index === 0 ? 'font-semibold' : '' }}">
                                                @if ($column === 'الجرد' && ! empty($row['url']))
                                                    <x-filament::link :href="$row['url']" target="_blank">{{ $row[$column] }}</x-filament::link>
                                                @else
                                                    {{ $row[$column] }}
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($alert['more'] > 0)
                        <div class="mt-2 text-xs text-gray-500">وكمان {{ number_format($alert['more']) }}…</div>
                    @endif
                @endif
            </x-filament::section>
        @endforeach
    </div>
</x-filament-widgets::widget>
