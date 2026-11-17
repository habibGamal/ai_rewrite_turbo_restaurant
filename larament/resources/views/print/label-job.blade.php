@php
    $width = rtrim(rtrim(number_format($template->rowWidthMm(), 2, '.', ''), '0'), '.');
    $height = rtrim(rtrim(number_format($template->height_mm, 2, '.', ''), '0'), '.');
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>طباعة ملصقات</title>
    <style>
        @page { size: {{ $width }}mm {{ $height }}mm; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Tahoma, Arial, sans-serif; }
        .toolbar { padding: 12px; font-size: 14px; display: flex; gap: 12px; align-items: center; }
        .page { width: {{ $width }}mm; height: {{ $height }}mm; page-break-after: always; overflow: hidden; }
        .page img { width: {{ $width }}mm; height: {{ $height }}mm; display: block; image-rendering: pixelated; }
        @media screen { .page { outline: 1px dashed #ccc; margin: 4px; } }
        @media print { .toolbar { display: none; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <strong>{{ $total }} ملصق</strong>
        <span>{{ $template->name }} ({{ $template->sizeLabel() }})</span>
        <button type="button" onclick="window.print()">طباعة</button>
        <span>في نافذة الطباعة: اختار طابعة الملصقات، والهوامش «بدون»، والحجم 100%.</span>
    </div>

    @foreach ($pages as $page)
        @for ($i = 0; $i < $page['copies']; $i++)
            <div class="page"><img src="{{ $page['image'] }}" alt=""></div>
        @endfor
    @endforeach

    <script>
        window.addEventListener('load', () => window.print());
    </script>
</body>
</html>
