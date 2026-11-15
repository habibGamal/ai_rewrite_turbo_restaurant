{{-- The order's unique reference: printed as a barcode the returns screen can scan. --}}
@php
    $reference = '#'.$order->id;
    $modules = array_sum(\App\Support\Barcodes\Code128::widths($reference)) + 20;
@endphp
<div class="reference" style="text-align: center; margin-top: 20px;">
    <div style="width: {{ min(520, $modules * 3) }}px; height: 70px; margin: 0 auto;">
        <div style="width: 100%; height: 100%;">{!! str_replace('<svg ', '<svg width="100%" height="100%" ', \App\Support\Barcodes\Code128::svg($reference, 70)) !!}</div>
    </div>
    <p>الرقم المرجعي: <span dir="ltr">{{ $reference }}</span></p>
</div>
