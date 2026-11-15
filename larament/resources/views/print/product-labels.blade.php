<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>ملصقات {{ $group->name }}</title>
    <style>
        @page { size: 38mm 25mm; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Tahoma, Arial, sans-serif; }
        .toolbar { padding: 12px; font-size: 14px; display: flex; gap: 12px; align-items: center; }
        .label {
            width: 38mm; height: 25mm; padding: 1mm 1.5mm;
            display: flex; flex-direction: column; justify-content: space-between;
            page-break-after: always; overflow: hidden;
        }
        .shop { font-size: 6pt; text-align: center; }
        .name { font-size: 7pt; font-weight: bold; text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .variant { font-size: 7pt; text-align: center; }
        .barcode svg { width: 100%; height: 8mm; display: block; }
        .code { font-size: 6pt; text-align: center; direction: ltr; letter-spacing: 0.5px; }
        .price { font-size: 9pt; font-weight: bold; text-align: center; }
        @media print { .toolbar { display: none; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <strong>{{ count($labels) }} ملصق</strong>
        @if ($missingBarcodes > 0)
            <span>({{ $missingBarcodes }} صنف بدون باركود لم يُطبع)</span>
        @endif
        <button type="button" onclick="window.print()">طباعة</button>
    </div>

    @foreach ($labels as $variant)
        <div class="label">
            @if ($shopName)
                <div class="shop">{{ $shopName }}</div>
            @endif
            <div class="name">{{ $group->name }}</div>
            <div class="variant">{{ $variant->variant_label }}</div>
            <div class="barcode">{!! \App\Support\Barcodes\Code128::svg($variant->barcode) !!}</div>
            <div class="code">{{ $variant->barcode }}</div>
            <div class="price">{{ number_format((float) $variant->price, 2) }} ج.م</div>
        </div>
    @endforeach

    @if (count($labels) > 0)
        <script>window.addEventListener('load', () => window.print());</script>
    @endif
</body>
</html>
