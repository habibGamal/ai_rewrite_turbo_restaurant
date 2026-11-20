<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5 mb-6">
        <div class="fi-wi-stats-overview-stat rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-sm font-medium text-gray-500 dark:text-gray-400">عدد الطلبات</div>
            <div class="text-3xl font-semibold tracking-tight text-gray-950 dark:text-white mt-2">{{ $stats['orders_count'] }}</div>
        </div>

        <div class="fi-wi-stats-overview-stat rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-sm font-medium text-gray-500 dark:text-gray-400">إجمالي الطلبات</div>
            <div class="text-3xl font-semibold tracking-tight text-gray-950 dark:text-white mt-2">{{ number_format($stats['orders_total'], 2) }}</div>
        </div>

        <div class="fi-wi-stats-overview-stat rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-sm font-medium text-gray-500 dark:text-gray-400">المحصل</div>
            <div class="text-3xl font-semibold tracking-tight text-gray-950 dark:text-white mt-2">{{ number_format($stats['collected'], 2) }}</div>
        </div>

        <div class="fi-wi-stats-overview-stat rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-sm font-medium text-gray-500 dark:text-gray-400">المدفوع للبائع</div>
            <div class="text-3xl font-semibold tracking-tight text-gray-950 dark:text-white mt-2">{{ number_format($stats['paid'], 2) }}</div>
        </div>

        <div class="fi-wi-stats-overview-stat rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="text-sm font-medium text-gray-500 dark:text-gray-400">المستحق</div>
            <div class="text-3xl font-semibold tracking-tight text-primary-600 dark:text-primary-400 mt-2">{{ number_format($stats['payable'], 2) }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">المستحق الكلي حتى الآن (لجميع الفترات)</div>
        </div>
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
                    الطلبات
                </button>
                <button
                    type="button"
                    @click="activeTab = 'settlements'"
                    :class="activeTab === 'settlements' ? 'border-primary-500 text-primary-600 dark:border-primary-400 dark:text-primary-400 font-bold' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 font-medium'"
                    class="flex whitespace-nowrap border-b-2 px-3 py-2 text-sm transition cursor-pointer"
                >
                    المدفوعات والمستحقات المسددة
                </button>
            </nav>
        </div>

        <div x-show="activeTab === 'orders'">
            {{ $this->table }}
        </div>

        <div x-show="activeTab === 'settlements'">
            @livewire(\App\Modules\ExternalSellers\Filament\Pages\ExternalSellerAccount\SettlementsTable::class, ['sellerId' => $this->seller->id])
        </div>
    </div>
</x-filament-panels::page>
