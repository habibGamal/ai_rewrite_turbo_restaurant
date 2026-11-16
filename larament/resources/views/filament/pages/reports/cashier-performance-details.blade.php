<x-filament-panels::page>
    @php
        $filter = $this->getResolvedFilter();
    @endphp

    <div class="mb-4">
        @livewire(\App\Filament\Widgets\CashierDetailStatsWidget::class, [
            'record' => $cashier,
            'shiftIds' => $filter['shiftIds'],
            'startDate' => $filter['startDate'],
            'endDate' => $filter['endDate'],
            'filterType' => $filter['filterType'],
            'hasFilter' => true,
        ], key('cashier-detail-stats-'.$cashier->id))
    </div>

    <div x-data="{ activeTab: @entangle('activeTab') }" class="space-y-4">
        <div class="border-b border-gray-200 dark:border-white/10">
            <nav class="-mb-px flex gap-x-6" aria-label="Tabs">
                <button
                    type="button"
                    @click="activeTab = 'orders'"
                    :class="activeTab === 'orders' ? 'border-primary-500 text-primary-600 dark:border-primary-400 dark:text-primary-400 font-bold' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 font-medium'"
                    class="flex whitespace-nowrap border-b-2 px-3 py-2 text-sm transition cursor-pointer"
                >
                    الطلبات المنشأة
                </button>
                <button
                    type="button"
                    @click="activeTab = 'items'"
                    :class="activeTab === 'items' ? 'border-primary-500 text-primary-600 dark:border-primary-400 dark:text-primary-400 font-bold' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 font-medium'"
                    class="flex whitespace-nowrap border-b-2 px-3 py-2 text-sm transition cursor-pointer"
                >
                    سجل تعديلات الأصناف
                </button>
            </nav>
        </div>

        <div x-show="activeTab === 'orders'">
            @livewire(\App\Filament\Widgets\CashierOrdersTableWidget::class, [
                'userId' => $cashier->id,
                'shiftIds' => $filter['shiftIds'],
                'startDate' => $filter['startDate'],
                'endDate' => $filter['endDate'],
                'filterType' => $filter['filterType'],
                'hasFilter' => true,
            ], key('cashier-orders-table-'.$cashier->id))
        </div>

        <div x-show="activeTab === 'items'">
            @livewire(\App\Filament\Widgets\CashierItemChangesTableWidget::class, [
                'userId' => $cashier->id,
                'shiftIds' => $filter['shiftIds'],
                'startDate' => $filter['startDate'],
                'endDate' => $filter['endDate'],
                'filterType' => $filter['filterType'],
                'hasFilter' => true,
            ], key('cashier-items-table-'.$cashier->id))
        </div>
    </div>
</x-filament-panels::page>
