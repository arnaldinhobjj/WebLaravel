@extends('layouts.app')

@section('title', $file->name)

@section('content')
    <a href="{{ $file->folder->isRoot() ? ($file->folder->isPersonal() ? route('files.mine') : route('files.library')) : route('files.folder', $file->folder) }}" class="back-link"><x-icon name="arrow-left" :size="16" /> {{ $file->folder->name }}</a>

    <div class="page-head">
        <div>
            <h2 class="section-title">{{ $file->name }}</h2>
            <p class="page-sub">{{ $file->folder->path() }}</p>
        </div>
        <div class="page-head-actions">
            <a href="{{ route('files.history', $file) }}" class="btn">Version history</a>
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
