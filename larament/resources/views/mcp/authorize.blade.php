@extends('mcp._layout')

@section('title', 'ربط المساعد الذكي')

@section('content')
    <h1>السماح لـ <span class="ltr">{{ $client->name }}</span> بالاطلاع على بيانات المتجر</h1>
    <p class="muted" style="text-align:center">المساعد الذكي — {{ config('app.name') }}</p>

    <div class="box">
        <p>سيتمكن هذا التطبيق من:</p>
        <ul>
            <li>قراءة المبيعات والأصناف والتقارير التي تملك صلاحية عرضها فقط.</li>
            <li>الإجابة عن أسئلتك عن أداء المتجر.</li>
        </ul>
        <p class="muted" style="margin-top:12px">لا يستطيع التطبيق تعديل أي بيانات أو تنفيذ أي عملية.</p>
    </div>

    <p class="muted">الحساب: <span class="ltr">{{ $user->email }}</span></p>

    @php($redirectUri = request()->query('redirect_uri', $client->redirect_uris[0] ?? null))
    @if ($redirectUri)
        <p class="muted">ستُنقل بعد الموافقة إلى: <span class="ltr">{{ $redirectUri }}</span></p>
    @endif

    <div class="actions">
        <form method="POST" action="{{ route('passport.authorizations.approve') }}">
            @csrf
            <input type="hidden" name="state" value="">
            <input type="hidden" name="client_id" value="{{ $client->id }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="primary">موافقة</button>
        </form>
        <form method="POST" action="{{ route('passport.authorizations.deny') }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="state" value="">
            <input type="hidden" name="client_id" value="{{ $client->id }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit">إلغاء</button>
        </form>
    </div>
@endsection
