@php
    $diagnosis = $this->cycleDiagnosisResult;
@endphp

@if ($diagnosis === null)
    <div class="rounded-xl border border-dashed border-gray-300 dark:border-gray-700 p-6 text-center text-sm text-gray-500 dark:text-gray-400">
        <div class="flex flex-col items-center justify-center gap-2">
            <x-heroicon-o-magnifying-glass class="w-8 h-8 text-gray-400" />
            <p class="font-medium text-gray-700 dark:text-gray-300">لم يتم إجراء فحص التبعيات الدائرية بعد</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">اضغط على زر "فحص التبعيات الدائرية" أعلاه لبدء فحص جميع وصفات المنتجات المصنعة في النظام.</p>
        </div>
    </div>
@elseif (empty($diagnosis['cycles']))
    <div class="rounded-xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 p-5 flex items-start gap-4">
        <div class="rounded-full bg-emerald-100 dark:bg-emerald-900/60 p-2 text-emerald-600 dark:text-emerald-400 shrink-0">
            <x-heroicon-m-check-circle class="w-6 h-6" />
        </div>
        <div class="space-y-1">
            <h4 class="font-bold text-emerald-900 dark:text-emerald-100 text-base">جميع وصفات المنتجات المصنعة سليمة!</h4>
            <p class="text-sm text-emerald-700 dark:text-emerald-300">
                تم فحص {{ $diagnosis['checked_count'] }} منتج مصنع بنجاح، ولم يتم العثور على أي تبعيات دائرية أو إشارات ذاتية في المكونات.
            </p>
            <span class="inline-block text-xs text-emerald-600 dark:text-emerald-400 pt-1">
                وقت الفحص: {{ $diagnosis['checked_at'] }}
            </span>
        </div>
    </div>
@else
    <div class="space-y-4">
        <div class="rounded-xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-800 p-5 flex items-start gap-4">
            <div class="rounded-full bg-rose-100 dark:bg-rose-900/60 p-2 text-rose-600 dark:text-rose-400 shrink-0">
                <x-heroicon-m-exclamation-triangle class="w-6 h-6" />
            </div>
            <div class="space-y-1">
                <h4 class="font-bold text-rose-900 dark:text-rose-100 text-base">
                    تم اكتشاف {{ count($diagnosis['cycles']) }} مشكلة تبعية دائرية!
                </h4>
                <p class="text-sm text-rose-700 dark:text-rose-300">
                    هذه التبعيات تتسبب في تكرار لا نهائي واستنفاد ذاكرة الخادم (Memory Exhaustion) عند إتمام الطلبات. اضغط على زر "إصلاح التبعيات الذاتية تلقائياً" لحذف المكونات الذاتية غير الصالحة.
                </p>
                <span class="inline-block text-xs text-rose-600 dark:text-rose-400 pt-1">
                    وقت الفحص: {{ $diagnosis['checked_at'] }}
                </span>
            </div>
        </div>

        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800/60">
                    <tr>
                        <th scope="col" class="px-4 py-3 text-right font-medium text-gray-700 dark:text-gray-300">رقم المنتج</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium text-gray-700 dark:text-gray-300">اسم المنتج المصنع</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium text-gray-700 dark:text-gray-300">نوع المشكلة</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium text-gray-700 dark:text-gray-300">مسار التبعية</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-900">
                    @foreach ($diagnosis['cycles'] as $cycle)
                        <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-800/40">
                            <td class="px-4 py-3 font-mono text-xs font-bold text-gray-600 dark:text-gray-400">#{{ $cycle['product_id'] }}</td>
                            <td class="px-4 py-3 font-semibold text-gray-900 dark:text-gray-100">{{ $cycle['product_name'] }}</td>
                            <td class="px-4 py-3">
                                @if ($cycle['is_self'])
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300">
                                        إشارة ذاتية مباشرة (المنتج يشير لنفسه)
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                                        تبعية دائرية متعددة
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-rose-600 dark:text-rose-400">
                                {{ implode(' ⟵ ', $cycle['cycle_path']) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
