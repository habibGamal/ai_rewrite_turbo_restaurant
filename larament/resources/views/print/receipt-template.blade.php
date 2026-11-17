@php
    use App\Enums\SettingKey;
    use Illuminate\Support\Facades\Storage;

    $display = $order->display();
    $receiptPayment = $receiptPayment ?? \App\Orders\ReceiptPayment::forOrder($order);
    $receiptView = $receiptView ?? \App\Orders\ReceiptView::forOrder($order);

    $receiptFooter = setting(SettingKey::RECEIPT_FOOTER);
    $logoPath =
        setting(SettingKey::RESTAURANT_PRINT_LOGO) !== ''
        ? Storage::disk('public')->path(setting(SettingKey::RESTAURANT_PRINT_LOGO))
        : null;
    $qrLogoPath =
        setting(SettingKey::RESTAURANT_QR_LOGO) !== '' ? Storage::disk('public')->path(setting(SettingKey::RESTAURANT_QR_LOGO)) : null;

    // Format dates
    $orderDate = $order->created_at->setTimezone('Africa/Cairo')->format('d/m/Y H:i:s');
    $printDate = now()->setTimezone('Africa/Cairo')->format('d/m/Y H:i:s');

    // Convert image to data URI
    $imgToDataUri = function ($imagePath) {
        if (!file_exists($imagePath)) {
            return '';
        }
        $imageData = file_get_contents($imagePath);
        $base64 = base64_encode($imageData);
        $mimeType = mime_content_type($imagePath);
        return "data:{$mimeType};base64,{$base64}";
    };
    $footerLogo = $imgToDataUri(public_path('images/turbo.png'));
@endphp

<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt</title>
    <style>
        /* @import url('https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&family=Cairo:wght@400;700&display=swap'); */

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            color: black;
        }

        body {
            /* font-family: sans-serif; */
            font-family: "Droid Sans Arabic", serif;
            direction: rtl;
            font-size: 22px;
            font-weight: bold;
            line-height: 1.4;
            color: black;
            background: white;
            padding: 20px;
            width: 572px;
            direction: rtl;
        }

        .receipt {
            width: 100%;
            space-y: 16px;
        }

        .receipt>* {
            margin-bottom: 16px;
        }

        .logo {
            display: block;
            margin: 0 auto;
            width: 200px;
            max-width: 50mm;
        }

        .order-number {
            font-size: 48px;
            text-align: center;
            font-weight: bold;
        }

        .center {
            text-align: center;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            border: 2px solid black;
            margin: 16px 0;
        }

        th,
        td {
            border: 1px solid black;
            padding: 8px;
            text-align: center;
            word-break: break-word;
            font-size: 20px;
        }

        th {
            background-color: #f0f0f0;
            font-weight: bold;
        }

        .product-cell {
            text-align: right;
            max-width: 200px;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        .number-cell {
            max-width: 80px;
            font-size: 18px;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .reference {
            text-align: center;
            margin-top: 20px;
        }

        .footer-text {
            white-space: pre-line;
            text-align: center;
        }

        .turbo-logo {
            display: block;
            margin: 20px auto;
            width: 200px;
            max-width: 50mm;
        }

        .footer-logos {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 20px;
            margin: 20px auto;
            text-align: center;
        }

        .footer-logos img {
            width: 150px;
            max-width: 40mm;
        }

        .company-info {
            text-align: center;
            font-size: 18px;
        }
    </style>
</head>

<body>
    <div class="receipt">
        @if ($logoPath)
            <img class="logo" src="{{ $imgToDataUri($logoPath) }}" alt="Logo" />
        @elseif($name = setting(SettingKey::RESTAURANT_NAME))
            <h1 style="text-align: center;">{{ $name }}</h1>
        @else
            <h1>-- TURBO --</h1>
        @endif

        <p class="order-number">Order #{{ $order->order_number }}</p>

        <p>الكاشير : {{ $order->user?->email }}</p>
        <p>تمت الطباعة بواسطة : {{ auth()->user()->email }}</p>
        <p>تاريخ الطلب : {{ $orderDate }}</p>
        <p>تاريخ الطباعة : {{ $printDate }}</p>
        <p>نوع الطلب : {{ $display->label }} </p>

        {{-- Conditional fields based on order type --}}
        @if ($display->showTable && $order->dine_table_number)
            <p>طاولة رقم {{ $order->dine_table_number }}</p>
        @endif

        {{-- Takeaway: show customer name and phone --}}
        @if ($display->showCustomer && $order->customer && ! $display->showAddress && ! $display->showDriver)
            <p>اسم العميل : {{ $order->customer?->name ?? '-' }}</p>
            <p>رقم الهاتف : {{ $order->customer?->phone ?? '-' }}</p>
        @endif

        {{-- Delivery: show phone, name, address, driver --}}
        @if ($display->showAddress || $display->showDriver)
            <p>رقم الهاتف : {{ $order->customer?->phone ?? '-' }}</p>
            <p>اسم العميل : {{ $order->customer?->name ?? '-' }}</p>
            @if ($display->showAddress)
                <p>العنوان : {{ $order->customer?->address ?? '-' }}</p>
            @endif
            @if ($display->showDriver)
                <p>السائق : {{ $order->driver?->name ?? '-' }}</p>
            @endif
        @endif

        <table>
            <thead>
                <tr>
                    <th>المنتج</th>
                    <th>الكمية</th>
                    <th>السعر</th>
                    <th>الاجمالي</th>
                    <th>الخصم</th>
                    <th>الصافي</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->items as $item)
                    @php
                        $itemSubtotal = $receiptView['restaurant_lines'][$item->id]['subtotal'];
                        $itemDiscount = $receiptView['restaurant_lines'][$item->id]['discount'];
                        $itemTotal = $receiptView['restaurant_lines'][$item->id]['total'];
                    @endphp
                    <tr>
                        <td class="product-cell">{{ $item->product->name }}</td>
                        <td class="number-cell">{{ $item->quantity }}</td>
                        <td class="number-cell">{{ number_format($item->price, 2) }}</td>
                        <td class="number-cell">{{ number_format($itemSubtotal, 2) }}</td>
                        <td class="number-cell" style="text-align: left; padding-right: 20px;">
                            @if ($item->item_discount_type === 'percent' && $item->item_discount_percent)
                                ({{ number_format($item->item_discount_percent, 0) }}%)
                            @endif
                            {{ number_format($itemDiscount, 2) }}
                        </td>

                        <td class="number-cell" style="background-color: #f9f9f9;">
                            {{ number_format($itemTotal, 2) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @include('print.receipt-charges')

        <table>
            <tbody>
                <tr>
                    <td>اجمالي الطلب</td>
                    <td class="number-cell">{{ number_format($order->sub_total, 2) }}</td>
                </tr>
                <tr>
                    <td>الخصم</td>
                    <td class="number-cell">{{ number_format($order->discount, 2) }}</td>
                </tr>
                <tr>
                    <td>الخدمة</td>
                    <td class="number-cell">{{ number_format($order->service, 2) }}</td>
                </tr>
                <tr>
                    <td>الضريبة</td>
                    <td class="number-cell">{{ number_format($order->tax, 2) }}</td>
                </tr>
                @if ((float) $order->equipment_total > 0)
                    <tr>
                        <td>المعدات</td>
                        <td class="number-cell">{{ number_format($order->equipment_total, 2) }}</td>
                    </tr>
                @endif
                @if ((float) $order->fees_total > 0)
                    <tr>
                        <td>رسوم إضافية</td>
                        <td class="number-cell">{{ number_format($order->fees_total, 2) }}</td>
                    </tr>
                @endif
                <tr>
                    <td>الاجمالي النهائي</td>
                    <td class="number-cell">{{ number_format($receiptView['restaurant_total'], 2) }}</td>
                </tr>
                @if ($receiptPayment->partial)
                    <tr>
                        <td>المدفوع</td>
                        <td class="number-cell">{{ number_format($receiptPayment->paid, 2) }}</td>
                    </tr>
                    <tr>
                        <td>المتبقي</td>
                        <td class="number-cell">{{ number_format($receiptPayment->remaining, 2) }}</td>
                    </tr>
                @endif
            </tbody>
        </table>

        @include('print.receipt-external')
        @include('print.receipt-reference')
        @if ($order->order_notes)
            <p>{{ $order->order_notes }}</p>
        @endif
        <p class="footer-text">{{ $receiptFooter }}</p>

        <div class="footer-logos">
            @if ($qrLogoPath)
                <img src="{{ $imgToDataUri($qrLogoPath) }}" alt="Restaurant QR Logo" />
            @else
                <img src="{{ $footerLogo }}" alt="Turbo Logo" />
            @endif
        </div>

        <p class="company-info">Turbo Software Space</p>
        <p class="center">{{ $printDate }}</p>
    </div>
</body>

</html>
