@extends('layouts.app')

@section('title', 'File settings')

@section('content')
    <div class="files-layout">
        @include('files.partials.nav', ['area' => 'settings'])
        <div class="files-main">
            <h2 class="section-title">File settings</h2>
            <p class="page-sub">In use: {{ number_format($usedBytes / 1048576, 1) }} MB, counting every version and the trash.</p>

            <form method="POST" action="{{ route('file-settings.update') }}" class="narrow-form">
                @csrf
                @method('PUT')
                <fieldset class="card" @disabled(! $canEdit)>
                    <div class="field-row field-row-2">
                        <label class="field">
                            <span>Largest file (MB)</span>
                            <input type="number" name="max_mb" min="1" max="2048" value="{{ old('max_mb', $maxMb) }}" required>
                            <small class="muted">The server itself accepts up to {{ $serverMaxMb }} MB (upload_max_filesize in php.ini).</small>
                            @error('max_mb') <p class="field-error">{{ $message }}</p> @enderror
                        </label>
                        <label class="field">
                            <span>Blocked file types</span>
                            <input type="text" name="blocked" value="{{ old('blocked', $blocked) }}" placeholder="exe, bat, sh">
                            <small class="muted">Extensions, separated by commas. Programs are refused whatever their name.</small>
                            @error('blocked') <p class="field-error">{{ $message }}</p> @enderror
                        </label>
                        <label class="field">
                            <span>My files limit per person (MB)</span>
                            <input type="number" name="personal_quota_mb" min="0" value="{{ old('personal_quota_mb', $personalQuotaMb) }}" required>
                            <small class="muted">0 means no limit.</small>
                            @error('personal_quota_mb') <p class="field-error">{{ $message }}</p> @enderror
                        </label>
                        <label class="field">
                            <span>Total storage limit (MB)</span>
                            <input type="number" name="total_quota_mb" min="0" value="{{ old('total_quota_mb', $totalQuotaMb) }}" required>
                            <small class="muted">0 means no limit.</small>
                            @error('total_quota_mb') <p class="field-error">{{ $message }}</p> @enderror
                        </label>
                    </div>
                </fieldset>
                @if ($canEdit)
                    <div class="form-actions"><button type="submit" class="btn btn-primary">Save file settings</button></div>
                @endif
            </form>
        </div>
    </div>
@endsection
