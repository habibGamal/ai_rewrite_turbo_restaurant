@if (!$analysisResult)
    <div></div>
@elseif (!$analysisResult['success'])
    <div class="p-3 text-sm text-red-700 bg-red-100 rounded border border-red-300">
        <strong>خطأ:</strong> {{ $analysisResult['error'] ?? 'حدث خطأ أثناء قراءة الملف' }}
    </div>
@else
    <div class="space-y-4">
        {{-- Seller Card --}}
        <div class="p-4 bg-gray-50 border border-gray-200 rounded-lg flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2">
            <div>
                <div class="flex items-center gap-2">
                    <span class="text-base font-bold text-gray-800">
                        {{ $analysisResult['seller']['name'] ?? 'بدون اسم' }}
                    </span>
                    @if (!empty($analysisResult['seller']['is_new']))
                        <span class="text-xs bg-amber-100 text-amber-800 font-semibold px-2 py-0.5 rounded">
                            بائع جديد سيتم إنشاؤه
                        </span>
                    @else
                        <span class="text-xs bg-emerald-100 text-emerald-800 font-semibold px-2 py-0.5 rounded">
                            بائع مسجل في النظام
                        </span>
                    @endif
                </div>
                @if (!empty($analysisResult['seller']['phone']))
                    <div class="text-xs text-gray-500 mt-1">
                        <strong>رقم الهاتف:</strong> {{ $analysisResult['seller']['phone'] }}
                    </div>
                @endif
            </div>

            <div class="text-left">
                <span class="text-2xl font-extrabold text-primary-600">
                    {{ $analysisResult['total_products'] }}
                </span>
                <span class="text-sm text-gray-600 mr-1">صنف مكتشف</span>
            </div>
        </div>

        {{-- Categories --}}
        @if (!empty($analysisResult['categories']) && count($analysisResult['categories']) > 0)
            <div>
                <strong class="text-xs font-semibold text-gray-600 block mb-1">الأقسام والفئات المكتشفة:</strong>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($analysisResult['categories'] as $cat)
                        <span class="text-xs bg-blue-50 text-blue-700 border border-blue-200 px-2 py-0.5 rounded font-medium">
                            {{ $cat }}
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Sample Data Table --}}
        @if (!empty($analysisResult['sample_data']) && count($analysisResult['sample_data']) > 0)
            <div class="overflow-x-auto border border-gray-200 rounded-lg">
                <table class="min-w-full text-sm divide-y divide-gray-200">
                    <thead class="bg-gray-100 text-gray-700">
                        <tr>
                            <th class="px-3 py-2 text-right">#</th>
                            @foreach ($analysisResult['headers'] as $header)
                                <th class="px-3 py-2 text-right font-semibold">{{ $header }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @foreach ($analysisResult['sample_data'] as $idx => $row)
                            <tr class="hover:bg-gray-50">
                                <td class="px-3 py-1.5 text-xs text-gray-400">{{ $idx + 1 }}</td>
                                <td class="px-3 py-1.5 font-medium text-gray-900">{{ $row['name'] ?? '' }}</td>
                                <td class="px-3 py-1.5 text-emerald-700 font-semibold">{{ $row['price'] ?? '' }}</td>
                                <td class="px-3 py-1.5 text-gray-600 text-xs">{{ $row['category'] ?? '' }}</td>
                                <td class="px-3 py-1.5">
                                    @if (($row['is_active'] ?? '') === 'نشط')
                                        <span class="text-xs text-emerald-700 bg-emerald-50 px-1.5 py-0.5 rounded">نشط</span>
                                    @else
                                        <span class="text-xs text-gray-500 bg-gray-100 px-1.5 py-0.5 rounded">غير نشط</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($analysisResult['total_products'] > count($analysisResult['sample_data']))
                <div class="text-xs text-gray-500 text-center">
                    ... بالإضافة إلى {{ $analysisResult['total_products'] - count($analysisResult['sample_data']) }} صنف إضافي سيتم استيرادها.
                </div>
            @endif
        @endif
    </div>
@endif
