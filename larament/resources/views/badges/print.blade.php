<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>بادج {{ $user->name }}</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f3f4f6; margin: 0; padding: 24px; }
        .card { width: 85.6mm; height: 54mm; box-sizing: border-box; background: #fff; border: 1px solid #d1d5db; border-radius: 3mm; padding: 4mm; margin: 0 auto; display: flex; flex-direction: column; justify-content: space-between; }
        .shop { font-size: 11pt; color: #4b5563; }
        .name { font-size: 15pt; font-weight: 700; }
        .barcode svg { width: 100%; height: 16mm; display: block; }
        .code { text-align: center; font-family: monospace; font-size: 8pt; letter-spacing: 1px; }
        .actions { text-align: center; margin-top: 16px; }
        .actions button, .actions a { font-size: 14px; padding: 8px 16px; margin: 0 4px; }
        .note { text-align: center; color: #b45309; margin-top: 12px; font-size: 13px; }
        @media print { body { background: #fff; padding: 0; } .actions, .note { display: none; } .card { border: none; margin: 0; } @page { size: 85.6mm 54mm; margin: 0; } }
    </style>
</head>
<body>
    @if ($code)
        <div class="card">
            <div>
                <div class="shop">{{ $shop }}</div>
                <div class="name">{{ $user->name }}</div>
            </div>
            <div class="barcode">{!! $svg !!}</div>
            <div class="code">{{ $code }}</div>
        </div>
        <div class="actions">
            <button type="button" onclick="window.print()">طباعة</button>
            <a href="{{ $back }}">رجوع</a>
        </div>
        <p class="note">الكود ده بيظهر مرة واحدة بس. لو الصفحة اتقفلت قبل الطباعة اعمل بادج جديد.</p>
    @else
        <p class="note">الكود ده اتعرض قبل كده. اعمل بادج جديد من صفحة المستخدمين.</p>
        <div class="actions"><a href="{{ $back }}">رجوع</a></div>
    @endif
</body>
</html>
