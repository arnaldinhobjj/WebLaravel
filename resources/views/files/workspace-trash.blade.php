@extends('layouts.app')

@section('title', $mine ? 'My trash · Workspace Files' : 'Library trash · Workspace Files')

@section('content')
    <div class="finder-window card">
        @include('files.partials.finder-sidebar', ['current' => $mine ? 'trash-mine' : 'trash'])

        <div class="finder-main">
            <div class="finder-toolbar">
                <a href="{{ route('workspace.index') }}" class="tool-btn" title="Back"><x-icon name="arrow-left" :size="18" /><span class="sr-only">Back</span></a>
                <nav class="finder-path" aria-label="Folder path">
                    <a href="{{ route('workspace.index') }}">Workspace Files</a>
                    <span aria-hidden="true">›</span>
                    <strong aria-current="page">{{ $mine ? 'My trash' : 'Library trash' }}</strong>
                </nav>
                <div class="finder-actions">
                    @if ($canEmpty && ($folders->isNotEmpty() || $files->isNotEmpty()))
                        <form method="POST" action="{{ route('files.trash.empty') }}" data-confirm="Delete everything in this trash for good? This can’t be undone.">
                            @csrf
                            <input type="hidden" name="area" value="{{ $mine ? 'mine' : 'library' }}">
                            <button type="submit" class="btn btn-ghost-danger">Empty trash</button>
                        </form>
                    @endif
                </div>
            </div>

            <div class="finder-grid-wrap">
                @if ($folders->isEmpty() && $files->isEmpty())
                    <p class="finder-empty muted">The trash is empty.</p>
                @else
                    <ul class="finder-grid" aria-label="{{ $mine ? 'My trash' : 'Library trash' }}">
                        @foreach ($folders as $item)
                            <li class="finder-item finder-trashed">
                                @include('files.partials.folder-icon', ['tone' => 'normal'])
                                <span class="finder-name">{{ $item->name }}</span>
                                <span class="finder-meta muted">{{ $item->deleted_at->diffForHumans() }}</span>
                                <form method="POST" action="{{ route('files.trash.restore', ['folder', $item->id]) }}">
                                    @csrf
                                    <button type="submit" class="link-btn" aria-label="Restore {{ $item->name }}">Restore</button>
                                </form>
                            </li>
                        @endforeach
                        @foreach ($files as $item)
                            <li class="finder-item finder-trashed" title="Was in {{ $item->folder?->path() }}">
                                @include('files.partials.file-icon', ['file' => $item])
                                <span class="finder-name">{{ $item->name }}</span>
                                <span class="finder-meta muted">{{ $item->deleted_at->diffForHumans() }}</span>
                                <form method="POST" action="{{ route('files.trash.restore', ['file', $item->id]) }}">
                                    @csrf
                                    <button type="submit" class="link-btn" aria-label="Restore {{ $item->name }}">Restore</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <p class="finder-status muted small">Items stay here for {{ \App\Services\Files\FileStore::TRASH_DAYS }} days, then they’re deleted for good.</p>
        </div>
    </div>
@endsection
