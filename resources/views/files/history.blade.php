@extends('layouts.app')

@section('title', 'History · '.$file->name)

@php
    $human = fn (int $bytes): string => $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : ($bytes >= 1024 ? number_format($bytes / 1024).' KB' : $bytes.' B');
@endphp

@section('content')
    <a href="{{ route('files.preview', $file) }}" class="back-link"><x-icon name="arrow-left" :size="16" /> {{ $file->name }}</a>

    <div class="page-head">
        <div>
            <h2 class="section-title">Version history</h2>
            <p class="page-sub">{{ $location['label'] }} / {{ $file->name }}. Restoring an old version adds it as the newest, so nothing is lost.</p>
        </div>
    </div>

    <div class="card table-scroll file-history">
        <table class="access-table">
            <thead><tr><th>Version</th><th>Uploaded</th><th>By</th><th>Size</th><th><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
                @foreach ($file->versions as $version)
                    <tr>
                        <td><strong>{{ $version->number }}</strong> @if ($version->id === $file->current_version_id) <span class="pill status-open">Current</span> @endif</td>
                        <td><time datetime="{{ $version->created_at->toIso8601String() }}">{{ $version->created_at->format('j M Y, H:i') }}</time></td>
                        <td>{{ $version->uploader?->name ?? '—' }}</td>
                        <td>{{ $human($version->size) }}</td>
                        <td class="file-row-actions">
                            <a href="{{ route('files.versions.download', [$file, $version]) }}" class="btn">Download</a>
                            @if ($canEdit && $version->id !== $file->current_version_id)
                                <form method="POST" action="{{ route('files.versions.restore', [$file, $version]) }}">
                                    @csrf
                                    <button type="submit" class="btn">Restore</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
