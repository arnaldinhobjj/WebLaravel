@extends('layouts.app')

@section('title', 'Search files')

@section('content')
    <div class="files-layout">
        @include('files.partials.nav', ['area' => 'search'])
        <div class="files-main">
            <h2 class="section-title">{{ $term === '' ? 'Search files' : 'Results for “'.$term.'”' }}</h2>
            @if ($term !== '' && $folders->isEmpty() && $files->isEmpty())
                <p class="muted">Nothing you can see matches.</p>
            @endif
            @if ($folders->isNotEmpty() || $files->isNotEmpty())
                <div class="card table-scroll">
                    <table class="access-table">
                        <thead><tr><th>Name</th><th>Where</th></tr></thead>
                        <tbody>
                            @foreach ($folders as $match)
                                <tr><td><a href="{{ route('files.folder', $match) }}"><strong>{{ $match->name }}</strong></a> <span class="muted small">folder</span></td><td class="muted">{{ $match->parent?->path() }}</td></tr>
                            @endforeach
                            @foreach ($files as $match)
                                <tr><td><a href="{{ route('files.preview', $match) }}">{{ $match->name }}</a></td><td class="muted">{{ $match->folder->path() }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
