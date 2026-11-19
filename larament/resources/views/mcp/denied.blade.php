@extends('mcp._layout')

@section('title', 'تعذّر ربط المساعد الذكي')

@section('content')
    <h1 class="danger">تعذّر ربط المساعد الذكي</h1>
    <p style="text-align:center">{{ $message }}</p>
    <p class="muted" style="text-align:center"><a href="{{ url('/admin') }}">العودة إلى لوحة التحكم</a></p>
@endsection
