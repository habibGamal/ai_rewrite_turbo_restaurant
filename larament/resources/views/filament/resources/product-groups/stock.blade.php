<x-filament-panels::page>
    @php($m = $this->matrix())

    <div class="flex flex-wrap gap-4 items-end">
        <label class="flex flex-col gap-1 text-sm">
            <span class="text-gray-500">المخزن</span>
            <select wire:model.live="warehouseId" class="fi-select-input rounded-lg border-gray-300 dark:bg-gray-800 dark:border-gray-700">
                <option value="">كل المخازن</option>
                @foreach ($this->warehouses() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>

        <label class="flex flex-col gap-1 text-sm">
            <span class="text-gray-500">الصفوف</span>
            <select wire:model.live="rowOptionId" class="fi-select-input rounded-lg border-gray-300 dark:bg-gray-800 dark:border-gray-700">
                @foreach ($m['axes'] as $axis)
                    <option value="{{ $axis->id }}">{{ $axis->name }}</option>
                @endforeach
            </select>
        </label>

        @if ($m['axes']->count() > 1)
            <label class="flex flex-col gap-1 text-sm">
                <span class="text-gray-500">الأعمدة</span>
                <select wire:model.live="colOptionId" class="fi-select-input rounded-lg border-gray-300 dark:bg-gray-800 dark:border-gray-700">
                    @foreach ($m['axes'] as $axis)
                        <option value="{{ $axis->id }}">{{ $axis->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        @foreach ($m['others'] as $axis)
            <label class="flex flex-col gap-1 text-sm">
                <span class="text-gray-500">{{ $axis->name }}</span>
                <select wire:model.live="fixed.{{ $axis->id }}" class="fi-select-input rounded-lg border-gray-300 dark:bg-gray-800 dark:border-gray-700">
                    <option value="">اختر</option>
                    @foreach ($axis->values as $value)
                        <option value="{{ $value->id }}">{{ $value->value }}</option>
                    @endforeach
                </select>
            </label>
        @endforeach
    </div>

    @if ($m['rows']->isEmpty())
        <x-filament::section>
            لا توجد خيارات لهذا الموديل بعد.
        </x-filament::section>
    @else
        @if ($m['others']->isNotEmpty() && collect($this->fixed)->filter()->count() < $m['others']->count())
            <p class="text-sm text-warning-600">اختر قيمة لكل خيار آخر لعرض كمية كل خانة.</p>
        @endif

        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
            <table class="w-full text-center text-sm" data-testid="stock-matrix">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="p-3 text-start">{{ $m['row']?->name }} / {{ $m['col']?->name }}</th>
                        @forelse ($m['cols'] as $col)
                            <th class="p-3">{{ $col->value }}</th>
                        @empty
                            <th class="p-3">الكمية</th>
                        @endforelse
                        @if ($m['cols']->isNotEmpty())
                            <th class="p-3">الإجمالي</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($m['rows'] as $row)
                        <tr class="border-t border-gray-200 dark:border-gray-700">
                            <th class="p-3 text-start">{{ $row->value }}</th>
                            @foreach (($m['cols']->isEmpty() ? [0] : $m['cols']->pluck('id')) as $colId)
                                @php($qty = $m['quantities'][$row->id][$colId] ?? null)
                                <td class="p-3 {{ $qty !== null && $qty <= 0 ? 'text-danger-600' : '' }}">
                                    {{ $qty === null ? '—' : rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.') }}
                                </td>
                            @endforeach
                            @if ($m['cols']->isNotEmpty())
                                <td class="p-3 font-semibold">{{ rtrim(rtrim(number_format($m['rowTotals'][$row->id] ?? 0, 3, '.', ''), '0'), '.') }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
                @if ($m['cols']->isNotEmpty())
                    <tfoot class="bg-gray-50 dark:bg-gray-800 font-semibold">
                        <tr>
                            <th class="p-3 text-start">الإجمالي</th>
                            @foreach ($m['cols'] as $col)
                                <td class="p-3">{{ rtrim(rtrim(number_format($m['colTotals'][$col->id] ?? 0, 3, '.', ''), '0'), '.') }}</td>
                            @endforeach
                            <td class="p-3" data-testid="stock-matrix-total">{{ rtrim(rtrim(number_format($m['total'], 3, '.', ''), '0'), '.') }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    @endif
</x-filament-panels::page>
