@extends('layouts.app')

@section('title', $folder->isRoot() ? $folder->name : $folder->name.' · Files')

@php
    $human = fn (int $bytes): string => $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : ($bytes >= 1024 ? number_format($bytes / 1024).' KB' : $bytes.' B');
    $folderUrl = fn ($f) => $f->isRoot() ? ($f->isPersonal() ? route('files.mine') : route('files.library')) : route('files.folder', $f);
@endphp

@section('content')
    <div class="files-layout">
        @include('files.partials.nav', ['area' => $folder->isPersonal() ? 'mine' : 'library'])

        <div class="files-main">
            <nav class="breadcrumbs" aria-label="Folder path">
                @foreach ($ancestry as $step)
                    @if ($loop->last)
                        <span aria-current="page">{{ $step->name }}</span>
                    @else
                        <a href="{{ $folderUrl($step) }}">{{ $step->name }}</a> <span aria-hidden="true">/</span>
                    @endif
                @endforeach
            </nav>

            <div class="page-head">
                <div>
                    <h2 class="section-title">{{ $folder->name }}</h2>
                    @if ($folder->isPersonal())
                        <p class="page-sub">Only you can see these. Using {{ $human($usage) }} of {{ \App\Services\Files\FileStore::setting('files.personal_quota_mb') }} MB.</p>
                    @elseif ($folder->isRoot())
                        <p class="page-sub">Shared files. Each top-level folder can be limited to certain tags or departments.</p>
                    @endif
                </div>
                <div class="page-head-actions">
                    <a href="{{ route('workspace.folder', $folder) }}" class="btn"><x-icon name="grid" :size="16" /> Icon view</a>
                    @if ($canManageAccess)
                        <a href="{{ route('files.access.edit', $folder) }}" class="btn">Who has access</a>
                    @endif
                    @if (! $folder->isRoot() && $can['edit'])
                        <details class="menu inline-menu">
                            <summary class="btn">Rename</summary>
                            <form method="POST" action="{{ route('files.folders.rename', $folder) }}" class="menu-list inline-form">
                                @csrf @method('PATCH')
                                <label class="sr-only" for="folder-rename">New name</label>
                                <input id="folder-rename" type="text" name="name" value="{{ $folder->name }}" required maxlength="120">
                                <button type="submit" class="btn btn-primary">Save</button>
                            </form>
                        </details>
                    @endif
                    @if (! $folder->isRoot() && $can['delete'])
                        <form method="POST" action="{{ route('files.folders.destroy', $folder) }}" data-confirm="Move the folder “{{ $folder->name }}” and everything in it to the trash?">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-ghost-danger">Delete folder</button>
                        </form>
                    @endif
                </div>
            </div>

            @if ($errors->any())
                <div class="yaml-errors-box" role="alert">
                    <ul class="yaml-errors">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            @if ($can['upload'])
                <div class="files-actions">
                    <form method="POST" action="{{ route('files.upload', $folder) }}" enctype="multipart/form-data" class="drop-zone" data-drop-zone>
                        @csrf
                        <x-icon name="plus" :size="18" />
                        <label>
                            <span>Drop files here or <u>choose files</u></span>
                            <input type="file" name="files[]" multiple class="sr-only" data-drop-input>
                        </label>
                        <small class="muted">Up to {{ \App\Services\Files\FileStore::setting('files.max_mb') }} MB each. A file with the same name becomes a new version.</small>
                        <noscript><button type="submit" class="btn btn-primary">Upload</button></noscript>
                    </form>
                    <details class="menu inline-menu">
                        <summary class="btn"><x-icon name="plus" :size="16" /> New folder</summary>
                        <form method="POST" action="{{ route('files.folders.store', $folder) }}" class="menu-list inline-form">
                            @csrf
                            <label class="sr-only" for="new-folder">Folder name</label>
                            <input id="new-folder" type="text" name="name" required maxlength="120" placeholder="Folder name">
                            <button type="submit" class="btn btn-primary">Create</button>
                        </form>
                    </details>
                </div>
            @endif

            <div class="card table-scroll">
                <table class="access-table files-table">
                    <thead><tr><th>Name</th><th>Size</th><th>Modified</th><th><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                        @foreach ($children as $child)
                            <tr>
                                <td><a href="{{ route('files.folder', $child) }}" class="file-name"><x-icon name="inbox" :size="18" /> <strong>{{ $child->name }}</strong></a>
                                    @if ($folder->isRoot() && ! $folder->isPersonal() && $child->access->isNotEmpty()) <span class="pill">Limited</span> @endif
                                </td>
                                <td class="muted">Folder</td>
                                <td class="muted"><time datetime="{{ $child->updated_at->toIso8601String() }}">{{ $child->updated_at->diffForHumans() }}</time></td>
                                <td></td>
                            </tr>
                        @endforeach
                        @foreach ($files as $file)
                            <tr>
                                <td>
                                    <a href="{{ route('files.preview', $file) }}" class="file-name"><x-icon name="list-checks" :size="18" /> {{ $file->name }}</a>
                                    @if ($file->versions_count > 1) <a href="{{ route('files.history', $file) }}" class="pill">v{{ $file->versions_count }}</a> @endif
                                </td>
                                <td class="muted">{{ $human($file->size) }}</td>
                                <td class="muted">
                                    <time datetime="{{ $file->updated_at->toIso8601String() }}">{{ $file->updated_at->diffForHumans() }}</time>
                                    @if ($file->currentVersion?->uploader) · {{ $file->currentVersion->uploader->name }} @endif
                                </td>
                                <td class="file-row-actions">
                                    <a href="{{ route('files.download', $file) }}" class="btn">Download</a>
                                    <details class="menu inline-menu">
                                        <summary class="btn" aria-label="More for {{ $file->name }}">…</summary>
                                        <div class="menu-list file-menu">
                                            <a href="{{ route('files.history', $file) }}" class="menu-item">Version history</a>
                                            @if ($can['edit'])
                                                <form method="POST" action="{{ route('files.rename', $file) }}" class="inline-form">
                                                    @csrf @method('PATCH')
                                                    <label class="sr-only" for="rename-{{ $file->id }}">New name</label>
                                                    <input id="rename-{{ $file->id }}" type="text" name="name" value="{{ $file->name }}" required maxlength="120">
                                                    <button type="submit" class="btn">Rename</button>
                                                </form>
                                                @if ($moveTargets->isNotEmpty())
                                                    <form method="POST" action="{{ route('files.move', $file) }}" class="inline-form">
                                                        @csrf @method('PATCH')
                                                        <label class="sr-only" for="move-{{ $file->id }}">Move to</label>
                                                        <select id="move-{{ $file->id }}" name="folder_id">
                                                            @foreach ($moveTargets as $target)
                                                                <option value="{{ $target->id }}">{{ $target->path() }}</option>
                                                            @endforeach
                                                        </select>
                                                        <button type="submit" class="btn">Move</button>
                                                    </form>
                                                @endif
                                            @endif
                                            @if ($can['delete'])
                                                <form method="POST" action="{{ route('files.destroy', $file) }}">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" class="menu-item danger">Move to trash</button>
                                                </form>
                                            @endif
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        @endforeach
                        @if ($children->isEmpty() && $files->isEmpty())
                            <tr><td colspan="4" class="muted">This folder is empty.</td></tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
