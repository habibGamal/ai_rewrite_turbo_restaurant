@if (count($receiptView['external_orders']) > 0)
    @foreach ($receiptView['external_orders'] as $external)
        <p style="margin-top: 16px; font-size: 24px;">طلب خارجي — {{ $external['seller_name'] }}</p>
        <table>
            <thead><tr><th>الصنف</th><th>الكمية</th><th>السعر</th><th>الإجمالي</th></tr></thead>
            <tbody>
                @foreach ($external['items'] as $item)
                    <tr>
                        <td class="product-cell">{{ $item['name'] }}</td>
                        <td class="number-cell">{{ $item['quantity'] }}</td>
                        <td class="number-cell">{{ number_format($item['price'], 2) }}</td>
                        <td class="number-cell">{{ number_format($item['total'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach
    <table>
        <tbody>
            <tr>
                <td>إجمالي المطعم</td>
                <td class="number-cell">{{ number_format($receiptView['restaurant_total'], 2) }}</td>
            </tr>
            <tr>
                <td>إجمالي الطلبات الخارجية</td>
                <td class="number-cell">{{ number_format($receiptView['external_total'], 2) }}</td>
            </tr>
            <tr style="border-top: 3px solid black;">
                <td style="font-size: 30px; padding: 12px 8px;">المطلوب من العميل</td>
                <td style="font-size: 36px; padding: 12px 8px;">{{ number_format($receiptView['guest_total'], 2) }}</td>
            </tr>
        </tbody>
    </table>
@endif
