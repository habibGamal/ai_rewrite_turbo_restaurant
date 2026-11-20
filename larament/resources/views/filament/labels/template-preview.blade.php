@php
    $scale = min(3, max(0.5, 520 / max(1, $width)));
@endphp
<div style="overflow:auto;padding:8px;background:#f3f4f6;border-radius:8px;direction:ltr">
    <div style="width:{{ (int) ceil($width * $scale) }}px;height:{{ (int) ceil($height * $scale) }}px;margin:0 auto">
        <iframe
            title="معاينة الملصق"
            srcdoc="{{ $html }}"
            width="{{ $width }}"
            height="{{ $height }}"
            scrolling="no"
            style="border:0;background:#fff;transform:scale({{ $scale }});transform-origin:0 0;image-rendering:pixelated;box-shadow:0 1px 3px rgba(0,0,0,.25)"
        ></iframe>
    </div>
    <p style="margin-top:8px;font-size:12px;color:#6b7280;text-align:center">{{ $width }} × {{ $height }} نقطة</p>
</div>
