@extends('layouts.app')

@section('title', $folder ? $folder->name.' · Workspace' : 'Workspace')

@php
    $me = auth()->user();
    $library = $me->can('files.view') ? \App\Models\Folder::libraryRoot() : null;
    $mine = $me->can('my_files.view') ? \App\Models\Folder::personalRootFor($me) : null;
    $parent = $folder && ! $folder->isRoot() ? $folder->parent : null;
    $kindOf = function (\App\Models\StoredFile $file): array {
        $extension = strtoupper($file->extension() ?: 'FILE');
        $color = match (true) {
            $file->mime === 'application/pdf' => 'pdf',
            str_starts_with((string) $file->mime, 'image/') => 'image',
            in_array($file->extension(), ['xls', 'xlsx', 'csv', 'ods', 'numbers'], true) => 'sheet',
            in_array($file->extension(), ['doc', 'docx', 'odt', 'pages', 'rtf'], true) => 'doc',
            in_array($file->extension(), ['ppt', 'pptx', 'key', 'odp'], true) => 'slides',
            in_array($file->extension(), ['zip', 'rar', '7z', 'gz', 'tar'], true) => 'archive',
            default => 'plain',
        };

        return [mb_substr($extension, 0, 4), $color];
    };
@endphp

@section('content')
    <div class="finder-window card">
        {{-- Sidebar, like the Finder's --}}
        <nav class="finder-sidebar" aria-label="Places">
            <p class="finder-sidebar-title">Places</p>
            <a href="{{ route('workspace.index') }}" @class(['is-active' => $folder === null])><x-icon name="grid" :size="16" /> Workspace</a>
            @if ($library)
                <a href="{{ route('workspace.folder', $library) }}" @class(['is-active' => $folder && ! $folder->isPersonal()])><x-icon name="inbox" :size="16" /> Library</a>
            @endif
            @if ($mine)
                <a href="{{ route('workspace.folder', $mine) }}" @class(['is-active' => $folder && $folder->isPersonal()])><x-icon name="user" :size="16" /> My files</a>
            @endif
            @canany(['files.delete', 'my_files.delete'])
                <p class="finder-sidebar-title">More</p>
                @can('files.delete')
                    <a href="{{ route('files.trash') }}"><x-icon name="x" :size="16" /> Library trash</a>
                @endcan
                @can('my_files.delete')
                    <a href="{{ route('files.trash', ['area' => 'mine']) }}"><x-icon name="x" :size="16" /> My trash</a>
                @endcan
            @endcanany
        </nav>

        <div class="finder-main">
            {{-- Toolbar: back, where you are, and what you can do here --}}
            <div class="finder-toolbar">
                @if ($folder)
                    <a href="{{ $parent ? route('workspace.folder', $parent) : route('workspace.index') }}" class="tool-btn" title="Back">
                        <x-icon name="arrow-left" :size="18" /><span class="sr-only">Back</span>
                    </a>
                @endif
                <nav class="finder-path" aria-label="Folder path">
                    <a href="{{ route('workspace.index') }}">Workspace</a>
                    @foreach ($ancestry as $step)
                        <span aria-hidden="true">›</span>
                        @if ($loop->last)
                            <strong aria-current="page">{{ $step->name }}</strong>
                        @else
                            <a href="{{ route('workspace.folder', $step) }}">{{ $step->name }}</a>
                        @endif
                    @endforeach
                </nav>
                <div class="finder-actions">
                    @if ($folder && $can['upload'])
                        <details class="menu inline-menu">
                            <summary class="btn"><x-icon name="plus" :size="16" /> New folder</summary>
                            <form method="POST" action="{{ route('files.folders.store', $folder) }}" class="menu-list inline-form">
                                @csrf
                                <input type="hidden" name="from" value="workspace">
                                <label class="sr-only" for="finder-new-folder">Folder name</label>
                                <input id="finder-new-folder" type="text" name="name" required maxlength="120" placeholder="Folder name">
                                <button type="submit" class="btn btn-primary">Create</button>
                            </form>
                        </details>
                    @endif
                    @if ($folder)
                        <a href="{{ $folder->isRoot() ? ($folder->isPersonal() ? route('files.mine') : route('files.library')) : route('files.folder', $folder) }}" class="btn" title="Rename, move, versions and more">List view</a>
                    @endif
                </div>
            </div>

            @if ($errors->any())
                <div class="yaml-errors-box" role="alert">
                    <ul class="yaml-errors">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            {{-- The icons. Dropping files anywhere here uploads them into this folder. --}}
            @if ($folder && $can['upload'])
                <form method="POST" action="{{ route('files.upload', $folder) }}" enctype="multipart/form-data" class="finder-grid-wrap" data-drop-zone>
                    @csrf
                    <input type="file" name="files[]" multiple class="sr-only" data-drop-input id="finder-upload">
            @else
                <div class="finder-grid-wrap">
            @endif

                <ul class="finder-grid" aria-label="{{ $folder ? 'Contents of '.$folder->name : 'Places' }}">
                    @foreach ($areas ?? [] as $area)
                        <li>
                            <a href="{{ route('workspace.folder', $area) }}" class="finder-item">
                                @include('files.partials.folder-icon', ['tone' => $area->isPersonal() ? 'personal' : 'shared'])
                                <span class="finder-name">{{ $area->isPersonal() ? 'My files' : $area->name }}</span>
                            </a>
                        </li>
                    @endforeach
                    @foreach ($children as $child)
                        <li>
                            <a href="{{ route('workspace.folder', $child) }}" class="finder-item">
                                @include('files.partials.folder-icon', ['tone' => $folder?->isRoot() && ! $folder->isPersonal() && $child->access->isNotEmpty() ? 'locked' : 'normal'])
                                <span class="finder-name">{{ $child->name }}</span>
                            </a>
                        </li>
                    @endforeach
                    @foreach ($files as $file)
                        @php [$label, $color] = $kindOf($file); @endphp
                        <li>
                            <a href="{{ route('files.preview', $file) }}" class="finder-item" title="{{ $file->name }}">
                                @if ($color === 'image' && in_array($file->mime, \App\Services\Files\FileStore::PREVIEWABLE, true))
                                    <span class="finder-thumb"><img src="{{ route('files.raw', $file) }}" alt="" loading="lazy"></span>
                                @else
                                    <span class="finder-doc finder-doc-{{ $color }}" aria-hidden="true">
                                        <svg viewBox="0 0 48 60" width="48" height="60"><path d="M4 2h28l14 14v40a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z" class="page"/><path d="M32 2v12a2 2 0 0 0 2 2h12" class="fold"/></svg>
                                        <span class="finder-doc-label">{{ $label }}</span>
                                    </span>
                                @endif
                                <span class="finder-name">{{ $file->name }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>

                @if ($folder && $children->isEmpty() && $files->isEmpty())
                    <p class="finder-empty muted">This folder is empty.@if ($can['upload']) Drop files here, or <label for="finder-upload"><u>choose files</u></label>.@endif</p>
                @elseif (! $folder && empty($areas))
                    <p class="finder-empty muted">You don’t have access to any files yet.</p>
                @endif

            @if ($folder && $can['upload'])
                    <p class="finder-hint muted small">Drop files anywhere here to upload them, or <label for="finder-upload"><u>choose files</u></label>.</p>
                </form>
            @else
                </div>
            @endif

            <p class="finder-status muted small">
                @if ($folder)
                    {{ $children->count() }} {{ Str::plural('folder', $children->count()) }}, {{ $files->count() }} {{ Str::plural('file', $files->count()) }}
                @endif
            </p>
        </div>
    </div>
@endsection
