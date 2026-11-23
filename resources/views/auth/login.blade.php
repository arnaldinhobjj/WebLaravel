<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · Fort Vale</title>
    <meta name="color-scheme" content="light dark">
    <link rel="stylesheet" href="{{ asset('css/fortvale.css') }}?v={{ filemtime(public_path('css/fortvale.css')) }}">
</head>
<body class="login-page">
<main class="card login-card">
    <div class="login-brand">
        <span class="brand-mark"><x-icon name="logo" :size="20" /></span>
        <h1>Fort Vale</h1>
    </div>

    <form method="POST" action="{{ route('login') }}" class="login-form">
        @csrf
        <label class="field">
            <span>Email</span>
            <input type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus @error('email') aria-invalid="true" @enderror>
            @error('email') <p class="field-error">{{ $message }}</p> @enderror
        </label>
        <label class="field">
            <span>Password</span>
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <label class="check"><input type="checkbox" name="remember" value="1"> Keep me signed in</label>
        <button type="submit" class="btn btn-primary">Sign in</button>
    </form>
</main>
</body>
</html>
