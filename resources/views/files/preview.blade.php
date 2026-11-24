@extends('layouts.app')

@section('title', $file->name)

@section('content')
    <a href="{{ $location['url'] }}" class="back-link"><x-icon name="arrow-left" :size="16" /> Back</a>

    <div class="page-head">
        <div>
            <h2 class="section-title">{{ $file->name }}</h2>
            <p class="page-sub">{{ $location['label'] }}</p>
        </div>
        <div class="page-head-actions">
            @if ($file->folder_id)
                <a href="{{ route('files.history', $file) }}" class="btn">Version history</a>
            @endif
            <a href="{{ route('files.download', $file) }}" class="btn btn-primary">Download</a>
        </div>
    </div>

    <div class="card file-preview">
        @switch($kind)
            @case('image')
                <img src="{{ route('files.raw', $file) }}" alt="{{ $file->name }}">
                @break
            @case('pdf')
                <iframe src="{{ route('files.raw', $file) }}" title="{{ $file->name }}"></iframe>
                @break
            @case('text')
                <pre class="file-text">{{ $text() }}</pre>
                @break
            @default
                <p class="muted">This kind of file can’t be shown here. Download it to open it.</p>
        @endswitch
    </div>
@endsection
