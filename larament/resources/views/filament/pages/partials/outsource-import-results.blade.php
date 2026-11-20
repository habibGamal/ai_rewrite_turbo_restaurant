@if (!$importResult)
    <div></div>
@elseif (!$importResult['success'])
    <div class="p-3 text-sm text-red-700 bg-red-100 rounded border border-red-300">
        <strong>خطأ في الاستيراد:</strong> {{ $importResult['error'] ?? 'حدث خطأ غير متوقع' }}
    </div>
@else
    <div class="space-y-3">
        <div class="p-4 bg-emerald-50 border border-emerald-200 rounded-lg">
            <div class="flex items-center justify-between">
                <div>
                    <h4 class="text-base font-bold text-emerald-800">
                        اكتملت عملية الاستيراد بنجاح!
                    </h4>
                    <p class="text-xs text-emerald-700 mt-1">
                        تم استيراد / تحديث <strong>{{ $importResult['imported_count'] }}</strong> صنف للبائع <strong>{{ $importResult['seller']['name'] ?? '' }}</strong>.
                    </p>
                </div>
                <div class="text-left">
                    <span class="text-2xl font-black text-emerald-600">
                        {{ $importResult['imported_count'] }}
                    </span>
                    <span class="text-xs text-emerald-700 mr-1">صنف</span>
                </div>
            </div>
        </div>

        @if (!empty($importResult['error_count']) && $importResult['error_count'] > 0)
            <div class="p-3 bg-red-50 border border-red-200 rounded-lg text-xs">
                <strong class="text-red-700 block mb-1">
                    حدثت {{ $importResult['error_count'] }} أخطاء أثناء المعالجة:
                </strong>
                <ul class="list-disc list-inside text-red-600 space-y-0.5">
                    @foreach (array_slice($importResult['errors'] ?? [], 0, 10) as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
@endif
