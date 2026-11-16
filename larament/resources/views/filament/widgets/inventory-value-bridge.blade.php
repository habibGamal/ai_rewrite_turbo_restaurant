@php
    $money = fn ($value) => number_format(abs((float) $value), 0);
    $colors = [
        'total' => 'bg-gray-400 dark:bg-gray-500',
        'in' => 'bg-teal-600 dark:bg-teal-500',
        'out' => 'bg-orange-600 dark:bg-orange-500',
    ];
@endphp
<x-filament-widgets::widget>
    <x-filament::section heading="قيمة المخزون اتحركت إزاي؟"
        description="من رصيد أول المدة لرصيد آخرها بتكلفة كل حركة. اضغط على أي عمود تظهر مستنداته تحت.">
        <div class="overflow-x-auto" data-testid="inventory-value-bridge">
            <div class="flex min-w-[720px] items-stretch gap-1.5">
                @foreach ($bars as $bar)
                    @php($clickable = $bar['key'] !== null)
                    <button type="button"
                        @if ($clickable) wire:click="select('{{ $bar['key'] }}')" @endif
                        @disabled(! $clickable)
                        title="{{ $bar['label'] }}: {{ number_format((float) $bar['delta'], 2) }}"
                        class="flex flex-1 flex-col items-center gap-1.5 rounded-lg px-0.5 py-1.5 {{ $clickable ? 'cursor-pointer hover:bg-gray-50 dark:hover:bg-white/5' : 'cursor-default' }}">
                        <span class="whitespace-nowrap text-xs font-semibold tabular-nums text-gray-950 dark:text-white">
                            @if ($bar['kind'] === 'total')
                                {{ $money($bar['delta']) }}
                            @else
                                {{ $bar['delta'] >= 0 ? '+' : '−' }}{{ $money($bar['delta']) }}
                            @endif
                        </span>
                        <span class="relative block h-56 w-full">
                            <span class="absolute inset-x-[14%] rounded {{ $colors[$bar['kind']] }}"
                                style="top: {{ $bar['top'] }}%; height: {{ $bar['height'] }}%"></span>
                        </span>
                        <span class="min-h-8 text-center text-xs leading-tight text-gray-700 dark:text-gray-300">{{ $bar['label'] }}</span>
                    </button>
                @endforeach
            </div>
        </div>
        <div class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-gray-500 dark:text-gray-400">
            <span class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm bg-teal-600"></span>دخل المخزن</span>
            <span class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm bg-orange-600"></span>خرج من المخزن</span>
            <span class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm bg-gray-400"></span>رصيد</span>
            @unless ($singleWarehouse)
                <span>التحويلات بين المخازن صافيها صفر على كل المخازن؛ اختار مخزن عشان تظهر.</span>
            @endunless
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
