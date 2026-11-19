<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') - {{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light dark; --bg: #f6f7f9; --card: #fff; --text: #111827; --muted: #6b7280; --border: #e5e7eb; --primary: #2563eb; --danger: #b91c1c; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0f1115; --card: #181b21; --text: #f3f4f6; --muted: #9ca3af; --border: #2a2f37; --primary: #3b82f6; --danger: #f87171; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; background: var(--bg); color: var(--text); font-family: Tahoma, "Segoe UI", system-ui, sans-serif; line-height: 1.6; }
        .card { width: 100%; max-width: 440px; background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 24px; }
        h1 { font-size: 1.25rem; margin: 0 0 8px; text-align: center; }
        p { margin: 0 0 12px; }
        .muted { color: var(--muted); font-size: .875rem; }
        .box { border: 1px solid var(--border); border-radius: 8px; padding: 12px; margin: 16px 0; }
        ul { margin: 0; padding-inline-start: 20px; }
        .actions { display: flex; gap: 12px; margin-top: 20px; }
        .actions form { flex: 1; }
        button { width: 100%; height: 42px; border-radius: 8px; font: inherit; cursor: pointer; border: 1px solid var(--border); background: transparent; color: var(--text); }
        button.primary { background: var(--primary); border-color: var(--primary); color: #fff; }
        .danger { color: var(--danger); }
        .ltr { direction: ltr; unicode-bidi: embed; word-break: break-all; }
    </style>
</head>
<body>
<main class="card">
    @yield('content')
</main>
</body>
</html>
