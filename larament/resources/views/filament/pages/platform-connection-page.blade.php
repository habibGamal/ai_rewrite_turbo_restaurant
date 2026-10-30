<x-filament-panels::page>
    <div class="space-y-6">
        <!-- Status Section -->
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-3">
                    <x-heroicon-m-signal class="w-5 h-5 text-primary-500" />
                    <span>حالة الاتصال بالمنصة</span>
                </div>
            </x-slot>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <!-- Status -->
                <div class="p-4 bg-gray-50 dark:bg-gray-800/50 rounded-lg border border-gray-100 dark:border-gray-800">
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">حالة الاتصال</div>
                    <div class="flex items-center gap-2">
                        <x-filament::badge :color="$this->getStatusColor()" size="md">
                            {{ $this->getStatusLabel() }}
                        </x-filament::badge>
                    </div>
                </div>

                <!-- Platform URL -->
                <div class="p-4 bg-gray-50 dark:bg-gray-800/50 rounded-lg border border-gray-100 dark:border-gray-800">
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">رابط المنصة</div>
                    <div class="text-sm font-semibold text-gray-900 dark:text-white truncate" dir="ltr">
                        {{ $this->getPlatformUrl() }}
                    </div>
                </div>

                <!-- Branch UUID -->
                <div class="p-4 bg-gray-50 dark:bg-gray-800/50 rounded-lg border border-gray-100 dark:border-gray-800">
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">معرف الفرع</div>
                    <div class="text-sm font-mono text-gray-900 dark:text-white truncate" dir="ltr">
                        {{ $this->getBranchUuid() }}
                    </div>
                </div>

                <!-- Last Success -->
                <div class="p-4 bg-gray-50 dark:bg-gray-800/50 rounded-lg border border-gray-100 dark:border-gray-800">
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">آخر نجاح</div>
                    <div class="text-sm font-semibold text-gray-900 dark:text-white">
                        {{ $this->getLastSuccess() }}
                    </div>
                </div>

                <!-- Last Heartbeat -->
                <div class="p-4 bg-gray-50 dark:bg-gray-800/50 rounded-lg border border-gray-100 dark:border-gray-800">
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">آخر نبضة</div>
                    <div class="text-sm font-semibold text-gray-900 dark:text-white">
                        {{ $this->getLastHeartbeat() }}
                    </div>
                </div>

                <!-- Pending Count -->
                <div class="p-4 bg-gray-50 dark:bg-gray-800/50 rounded-lg border border-gray-100 dark:border-gray-800">
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">السجلات المعلقة</div>
                    <div class="text-sm font-semibold text-gray-900 dark:text-white">
                        {{ $this->getPendingCount() }}
                    </div>
                </div>

                <!-- Oldest Pending Age -->
                <div class="p-4 bg-gray-50 dark:bg-gray-800/50 rounded-lg border border-gray-100 dark:border-gray-800">
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">عمر أقدم معلق</div>
                    <div class="text-sm font-semibold text-gray-900 dark:text-white">
                        {{ $this->getOldestPendingAge() }}
                    </div>
                </div>

                <!-- Failed Count -->
                <div class="p-4 bg-gray-50 dark:bg-gray-800/50 rounded-lg border border-gray-100 dark:border-gray-800">
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">السجلات الفاشلة</div>
                    <div class="text-sm font-semibold text-danger-600 dark:text-danger-400">
                        {{ $this->getFailedCount() }}
                    </div>
                </div>

                <!-- Last Error -->
                <div class="p-4 bg-gray-50 dark:bg-gray-800/50 rounded-lg border border-gray-100 dark:border-gray-800">
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">آخر خطأ</div>
                    <div class="text-sm text-gray-900 dark:text-gray-300 truncate" title="{{ $this->getLastError() }}">
                        {{ $this->getLastError() }}
                    </div>
                </div>
            </div>
        </x-filament::section>

        <!-- Failed Outbox Table -->
        <div>
            {{ $this->table }}
        </div>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
