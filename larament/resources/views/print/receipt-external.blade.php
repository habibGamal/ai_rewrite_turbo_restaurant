@if (count($receiptView['external_orders']) > 0)
    @foreach ($receiptView['external_orders'] as $external)
        <p>طلب خارجي — {{ $external['seller_name'] }}</p>
        <table>
            <thead><tr><th>الصنف</th><th>الكمية</th><th>السعر</th><th>الإجمالي</th></tr></thead>
            <tbody>
                @foreach ($external['items'] as $item)
                    <tr>
                        <td>{{ $item['name'] }}</td>
                        <td>{{ $item['quantity'] }}</td>
                        <td>{{ number_format($item['price'], 2) }}</td>
                        <td>{{ number_format($item['total'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach
    <p>إجمالي المطعم: {{ number_format($receiptView['restaurant_total'], 2) }}</p>
    <p>إجمالي الطلبات الخارجية: {{ number_format($receiptView['external_total'], 2) }}</p>
    <p>المطلوب من العميل: {{ number_format($receiptView['guest_total'], 2) }}</p>
@endif
