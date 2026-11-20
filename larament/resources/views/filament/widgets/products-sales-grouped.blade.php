<x-filament-widgets::widget>
    <x-filament::section>
        <div x-data="{ search: '' }" class="space-y-4">
            {{-- Header with Title & Action Controls --}}
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-b border-gray-200 pb-3 dark:border-white/10">
                <div>
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                        المبيعات حسب التصنيف الرئيسي والفرعي
                    </h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        اضغط على أي تصنيف لعرض المنتجات التابعة له (يتم تحميل البيانات عند الطلب)
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    {{-- Quick Search Input --}}
                    <div class="relative">
                        <input
                            type="text"
                            x-model="search"
                            placeholder="بحث في التصنيفات..."
                            class="h-8 w-44 sm:w-56 rounded-lg border border-gray-300 bg-white px-2.5 py-1 text-xs text-gray-950 placeholder-gray-400 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white dark:placeholder-gray-500"
                        />
                        <button
                            type="button"
                            x-show="search"
                            x-on:click="search = ''"
                            class="absolute end-2 top-1/2 -translate-y-1/2 text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                            style="display: none;"
                        >
                            ✕
                        </button>
                    </div>

                    {{-- Expand / Collapse Controls --}}
                    <x-filament::button
                        size="xs"
                        color="gray"
                        icon="heroicon-m-arrows-pointing-out"
                        wire:click="expandAll"
                    >
                        توسيع الكل
                    </x-filament::button>

                    <x-filament::button
                        size="xs"
                        color="gray"
                        icon="heroicon-m-arrows-pointing-in"
                        wire:click="collapseAll"
                    >
                        طي الكل
                    </x-filament::button>
                </div>
            </div>

            {{-- Categories Accordion List --}}
            <div class="space-y-3">
                @forelse ($categories as $category)
                    @php
                        $catId = $category['main_category_id'];
                        $isExpanded = in_array($catId, $expandedCategories, true);
                        $groupData = $details[$catId] ?? null;
                    @endphp

                    <div
                        wire:key="category-group-{{ $catId }}"
                        x-show="!search || '{{ addslashes($category['main_category_name']) }}'.toLowerCase().includes(search.toLowerCase())"
                        class="overflow-hidden rounded-xl border border-gray-200 transition-colors dark:border-white/10 bg-white dark:bg-white/[0.02]"
                    >
                        {{-- Accordion Header (Clickable) --}}
                        <button
                            type="button"
                            wire:click="toggleCategory({{ $catId }})"
                            class="flex w-full items-center justify-between gap-4 px-4 py-3 text-start transition hover:bg-gray-50 dark:hover:bg-white/5 focus:outline-none {{ $isExpanded ? 'bg-gray-50/80 dark:bg-white/[0.04]' : '' }}"
                        >
                            {{-- Category Title & Item Count --}}
                            <div class="flex items-center gap-3">
                                <span
                                    class="text-gray-400 transition-transform duration-200 dark:text-gray-500 {{ $isExpanded ? 'rotate-180' : '' }}"
                                >
                                    <x-filament::icon icon="heroicon-m-chevron-down" class="h-5 w-5" />
                                </span>

                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-semibold text-gray-950 dark:text-white">
                                            {{ $category['main_category_name'] }}
                                        </span>
                                        <span class="rounded-md bg-gray-100 px-1.5 py-0.5 text-[11px] font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">
                                            {{ $category['products_count'] }} صنف
                                        </span>
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">التصنيف الرئيسي</div>
                                </div>
                            </div>

                            {{-- Financial Totals & Loading Indicator --}}
                            <div class="flex items-center gap-4 text-end text-sm">
                                <div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ number_format($category['total_quantity'], 3) }} وحدة
                                    </div>
                                    <div class="font-semibold text-gray-950 dark:text-white">
                                        {{ number_format($category['total_sales'], 2) }} ج.م
                                    </div>
                                    <div class="text-xs text-emerald-600 dark:text-emerald-400 font-medium">
                                        ربح {{ number_format($category['total_profit'], 2) }} ج.م
                                    </div>
                                </div>

                                {{-- Loading Spinner when expanding this specific category --}}
                                <div wire:loading wire:target="toggleCategory({{ $catId }})">
                                    <x-filament::loading-indicator class="h-5 w-5 text-primary-500" />
                                </div>
                            </div>
                        </button>

                        {{-- Expanded Category Body (Subcategories & Product Table) --}}
                        @if ($isExpanded)
                            <div class="border-t border-gray-200 bg-gray-50/30 dark:border-white/10 dark:bg-black/10">
                                @if ($groupData)
                                    @foreach ($groupData['sub_categories'] as $sub)
                                        <div class="border-t first:border-t-0 border-gray-200 px-4 py-3 dark:border-white/10">
                                            {{-- Subcategory Title & Subtotals --}}
                                            <div class="mb-2 flex items-center justify-between gap-4">
                                                <div class="text-sm font-medium text-gray-800 dark:text-gray-200">
                                                    {{ $sub['sub_category_name'] ?? 'أصناف التصنيف الرئيسي المباشرة' }}
                                                </div>
                                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                                    {{ number_format($sub['total_quantity'], 3) }} · {{ number_format($sub['total_sales'], 2) }} ج.م · <span class="text-emerald-600 dark:text-emerald-400 font-medium">ربح {{ number_format($sub['total_profit'], 2) }} ج.م</span>
                                                </div>
                                            </div>

                                            {{-- Products Table --}}
                                            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/5">
                                                <table class="w-full text-sm">
                                                    <thead>
                                                        <tr class="bg-gray-100/60 text-start text-xs text-gray-500 dark:bg-white/5 dark:text-gray-400">
                                                            <th class="py-2 px-3 text-start font-medium">المنتج</th>
                                                            <th class="py-2 px-3 text-end font-medium">الكمية</th>
                                                            <th class="py-2 px-3 text-end font-medium">المبيعات</th>
                                                            <th class="py-2 px-3 text-end font-medium">الربح</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                                        @foreach ($sub['products'] as $product)
                                                            <tr class="hover:bg-gray-50/50 dark:hover:bg-white/[0.02]">
                                                                <td class="py-2 px-3 text-start font-medium text-gray-900 dark:text-gray-100">
                                                                    {{ $product['name'] }}
                                                                </td>
                                                                <td class="py-2 px-3 text-end text-gray-600 dark:text-gray-400">
                                                                    {{ number_format($product['total_quantity'], 3) }}
                                                                </td>
                                                                <td class="py-2 px-3 text-end font-medium text-gray-900 dark:text-gray-100">
                                                                    {{ number_format($product['total_sales'], 2) }} ج.م
                                                                </td>
                                                                <td class="py-2 px-3 text-end font-medium text-emerald-600 dark:text-emerald-400">
                                                                    {{ number_format($product['total_profit'], 2) }} ج.م
                                                                </td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    @endforeach
                                @else
                                    {{-- Loading Skeleton Placeholder --}}
                                    <div class="p-4 space-y-2 animate-pulse">
                                        <div class="h-4 bg-gray-200 rounded w-1/4 dark:bg-white/10"></div>
                                        <div class="h-8 bg-gray-100 rounded w-full dark:bg-white/5"></div>
                                        <div class="h-8 bg-gray-100 rounded w-full dark:bg-white/5"></div>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="rounded-xl border border-dashed border-gray-300 p-6 text-center dark:border-white/10">
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            لا توجد مبيعات في الفترة أو التصنيفات المحددة
                        </p>
                    </div>
                @endforelse
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
