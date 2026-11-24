@extends('layouts.app')

@section('title', 'Settings')

@section('content')
    <div class="page-head">
        <div>
            <h2 class="section-title">Settings <x-icon name="settings" :size="24" class="title-icon" /></h2>
            <p class="page-sub">Your profile and how Fort Vale looks for you.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('settings.update', $user) }}" class="settings-form">
        @csrf
        @method('PUT')

        <section class="card settings-section" aria-labelledby="profile-title">
            <div class="settings-intro">
                <h3 id="profile-title" class="sub-title">Profile</h3>
                <p class="muted">Shown on tickets, replies and in the profile menu.</p>
            </div>
            <div class="settings-fields">
                <div class="field-row field-row-2">
                    <label class="field">
                        <span>Name</span>
                        <input type="text" name="name" value="{{ old('name', $user->name) }}" required maxlength="80" @error('name') aria-invalid="true" @enderror>
                        @error('name') <p class="field-error">{{ $message }}</p> @enderror
                    </label>
                    <label class="field">
                        <span>Job title</span>
                        <input type="text" name="job_title" value="{{ old('job_title', $user->job_title) }}" maxlength="80" @error('job_title') aria-invalid="true" @enderror>
                        @error('job_title') <p class="field-error">{{ $message }}</p> @enderror
                    </label>
                </div>
                <div class="field-row field-row-2">
                    <label class="field">
                        <span>Username</span>
                        <span class="input-prefix">
                            <span aria-hidden="true">/</span>
                            <input type="text" name="username" value="{{ old('username', $user->username) }}" required minlength="3" maxlength="30" autocapitalize="none" spellcheck="false" @error('username') aria-invalid="true" @enderror>
                        </span>
                        <small class="muted">Used in your page addresses, like /{{ $user->username }}/settings.</small>
                        @error('username') <p class="field-error">{{ $message }}</p> @enderror
                    </label>
                    <label class="field">
                        <span>Email</span>
                        <input type="email" name="email" value="{{ old('email', $user->email) }}" required maxlength="255" @error('email') aria-invalid="true" @enderror>
                        @error('email') <p class="field-error">{{ $message }}</p> @enderror
                    </label>
                </div>
            </div>
        </section>

        <section class="card settings-section" aria-labelledby="appearance-title">
            <div class="settings-intro">
                <h3 id="appearance-title" class="sub-title">Appearance</h3>
                <p class="muted">Saved to your profile, so it follows you to any computer.</p>
            </div>
            <fieldset class="settings-fields theme-options">
                <legend class="sr-only">Theme</legend>
                @foreach (\App\Models\User::THEMES as $value => $label)
                    <label class="theme-option">
                        <input type="radio" name="theme" value="{{ $value }}" @checked(old('theme', $user->theme) === $value)>
                        <span class="theme-swatch theme-swatch-{{ $value }}" aria-hidden="true"></span>
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
                @error('theme') <p class="field-error">{{ $message }}</p> @enderror
            </fieldset>
        </section>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save settings</button>
        </div>
    </form>

    <form method="POST" action="{{ route('settings.password', $user) }}" class="card settings-section" aria-labelledby="password-title">
        @csrf
        @method('PUT')
        <div class="settings-intro">
            <h3 id="password-title" class="sub-title">Password</h3>
            <p class="muted">Used to sign in with {{ $user->email }}.</p>
        </div>
        <div class="settings-fields">
            <div class="field-row field-row-2">
                <label class="field">
                    <span>Current password</span>
                    <input type="password" name="current_password" required autocomplete="current-password" @error('current_password', 'password') aria-invalid="true" @enderror>
                    @error('current_password', 'password') <p class="field-error">{{ $message }}</p> @enderror
                </label>
                <span></span>
                <label class="field">
                    <span>New password</span>
                    <input type="password" name="password" required minlength="8" autocomplete="new-password" @error('password', 'password') aria-invalid="true" @enderror>
                    @error('password', 'password') <p class="field-error">{{ $message }}</p> @enderror
                </label>
                <label class="field">
                    <span>New password again</span>
                    <input type="password" name="password_confirmation" required autocomplete="new-password">
                </label>
            </div>
            <div><button type="submit" class="btn">Change password</button></div>
        </div>
    </form>

    <section class="card settings-section" aria-labelledby="home-title">
        <div class="settings-intro">
            <h3 id="home-title" class="sub-title">Home screen</h3>
            @can('actions-page')
                <p class="muted">Your own actions are managed on the <a href="{{ route('actions.index', $user) }}">actions page</a>.</p>
            @endcan
        </div>
        <div class="settings-fields settings-row">
            <p>Put favorites back to the defaults, clear action run times and bring back dismissed mentions.</p>
            <form method="POST" action="{{ route('settings.reset-home') }}">
                @csrf
                <button type="submit" class="btn"><x-icon name="rotate" :size="16" /> Reset home screen</button>
            </form>
        </div>
    </section>

    <section class="card settings-section" aria-labelledby="shortcuts-title">
        <div class="settings-intro">
            <h3 id="shortcuts-title" class="sub-title">Keyboard shortcuts</h3>
        </div>
        <dl class="settings-fields shortcuts">
            <div><dt><kbd>Ctrl</kbd> <kbd>K</kbd></dt><dd>Search</dd></div>
            <div><dt><kbd>[</kbd></dt><dd>Show or hide the sidebar</dd></div>
            <div><dt><kbd>N</kbd></dt><dd>New ticket</dd></div>
            <div><dt><kbd>Esc</kbd></dt><dd>Close menus and dialogs, clear search</dd></div>
        </dl>
    </section>
@endsection
