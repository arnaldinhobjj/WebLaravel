<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @if (in_array($currentUser->theme, ['light', 'dark'], true)) data-theme="{{ $currentUser->theme }}" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Home') · Fort Vale</title>
    <meta name="color-scheme" content="light dark">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%232456d6' stroke-width='2' stroke-linejoin='round'%3E%3Cpath d='M4 21V8l2-1V4h2.5v2h2V4h3v2h2V4H18v3l2 1v13h-5.5v-4.5a2.5 2.5 0 0 0-5 0V21z'/%3E%3C/svg%3E">
    <link rel="stylesheet" href="{{ asset('css/fortvale.css') }}?v={{ filemtime(public_path('css/fortvale.css')) }}">
    <script>
        try {
            if (localStorage.getItem('fv.sidebar') === 'collapsed') document.documentElement.classList.add('sidebar-collapsed');
        } catch (e) {}
    </script>
</head>
<body data-new-ticket-url="{{ route('tickets.create') }}">
<div class="window">
    @include('partials.sidebar')

    <div class="main">
        @include('partials.titlebar')

        <main class="content" id="content">
            @yield('content')
        </main>
    </div>
</div>

@if (session('status'))
    <div class="toast" role="status" data-toast>{{ session('status') }}</div>
@endif

<script src="{{ asset('js/fortvale.js') }}?v={{ filemtime(public_path('js/fortvale.js')) }}" defer></script>
</body>
</html>
