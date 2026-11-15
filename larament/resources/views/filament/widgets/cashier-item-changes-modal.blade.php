<div class="space-y-4">
    @if($changes->isEmpty())
        <div class="text-center py-6 text-gray-500 dark:text-gray-400">
            لا توجد تعديلات أصناف مسجلة لهذا الكاشير في الفترة المحددة.
        </div>
    @else
        <div class="overflow-x-auto max-h-[450px]">
            <table class="w-full text-sm text-right divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 font-semibold sticky top-0">
                    <tr>
                        <th class="py-2 px-3">رقم الطلب</th>
                        <th class="py-2 px-3">اسم المنتج</th>
                        <th class="py-2 px-3">نوع الحركة</th>
                        <th class="py-2 px-3">الكمية</th>
                        <th class="py-2 px-3">سعر الوحدة</th>
                        <th class="py-2 px-3">إجمالي القيمة</th>
                        <th class="py-2 px-3">التاريخ والوقت</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($changes as $change)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                            <td class="py-2 px-3 font-medium">
                                <a href="{{ url('/admin/orders/' . $change->order_id) }}" target="_blank" class="text-primary-600 hover:underline">
                                    #{{ $change->order?->order_number ?? $change->order_id }}
                                </a>
                            </td>
                            <td class="py-2 px-3">{{ $change->product_name }}</td>
                            <td class="py-2 px-3">
                                @if($change->change_type === 'added' || $change->delta > 0)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400">
                                        إضافة
                                    </span>
                                @elseif($change->change_type === 'removed' || $change->delta < 0)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400">
                                        حذف
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400">
                                        تعديل
                                    </span>
                                @endif
                            </td>
                            <td class="py-2 px-3 font-semibold {{ $change->delta >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                {{ $change->delta > 0 ? '+' : '' }}{{ (float) $change->delta }}
                            </td>
                            <td class="py-2 px-3">{{ number_format((float) $change->unit_price, 2) }} ج.م</td>
                            <td class="py-2 px-3 font-semibold {{ $change->delta >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                {{ $change->delta > 0 ? '+' : '-' }}{{ number_format((float) $change->total_price, 2) }} ج.م
                            </td>
                            <td class="py-2 px-3 text-xs text-gray-500">
                                {{ $change->created_at ? $change->created_at->format('Y-m-d H:i') : '-' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
