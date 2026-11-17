{{-- Equipment and custom fee lines, so the final total adds up on paper. --}}
@if (count($receiptView['equipment_lines'] ?? []) > 0)
    <p style="margin-top: 16px; font-size: 24px;">المعدات</p>
    <table>
        <thead><tr><th>البيان</th><th>الكمية</th><th>السعر</th><th>الإجمالي</th></tr></thead>
        <tbody>
            @foreach ($receiptView['equipment_lines'] as $line)
                <tr>
                    <td class="product-cell">{{ $line['name'] }}</td>
                    <td class="number-cell">{{ $line['quantity'] }}</td>
                    <td class="number-cell">{{ number_format($line['price'], 2) }}</td>
                    <td class="number-cell">{{ number_format($line['total'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
@if (count($receiptView['fee_lines'] ?? []) > 0)
    <p style="margin-top: 16px; font-size: 24px;">رسوم إضافية</p>
    <table>
        <thead><tr><th>البيان</th><th>المبلغ</th></tr></thead>
        <tbody>
            @foreach ($receiptView['fee_lines'] as $fee)
                <tr>
                    <td class="product-cell">{{ $fee['name'] }}</td>
                    <td class="number-cell">{{ number_format($fee['amount'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
