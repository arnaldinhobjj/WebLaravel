@extends('layouts.app')

@section('title', $folder ? $folder->name.' · Files' : 'Files')

@php
    $parent = $folder && ! $folder->isRoot() ? $folder->parent : null;
@endphp

@section('content')
    <div class="finder-window card">
        @include('files.partials.finder-sidebar', ['current' => $folder === null ? 'top' : ($folder->isPersonal() ? 'mine' : 'library')])

        <div class="finder-main">
            {{-- Toolbar: back, where you are, and what you can do here --}}
            <div class="finder-toolbar">
                @if ($folder)
                    <a href="{{ $parent ? route('workspace.folder', $parent) : route('workspace.index') }}" class="tool-btn" title="Back">
                        <x-icon name="arrow-left" :size="18" /><span class="sr-only">Back</span>
                    </a>
                @endif
                <nav class="finder-path" aria-label="Folder path">
                    <a href="{{ route('workspace.index') }}">Files</a>
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
                    @if ($newIn->isNotEmpty())
                        <details class="menu finder-new">
                            <summary class="btn btn-primary"><x-icon name="plus" :size="16" /> New</summary>
                            <div class="menu-list finder-new-menu">
                                @foreach ($newIn as $target)
                                    <form method="POST" action="{{ route('files.folders.store', $target) }}" class="finder-new-folder">
                                        @csrf
                                        <input type="hidden" name="from" value="workspace">
                                        <label for="new-folder-{{ $target->id }}">{{ $folder ? 'New folder' : 'New folder in '.($target->isPersonal() ? 'My files' : $target->name) }}</label>
                                        <span class="inline-form">
                                            <input id="new-folder-{{ $target->id }}" type="text" name="name" required maxlength="120" placeholder="Folder name">
                                            <button type="submit" class="btn btn-primary">Create</button>
                                        </span>
                                    </form>
                                @endforeach
                                @if ($folder && $can['upload'])
                                    <label for="finder-upload" class="menu-item"><x-icon name="plus" :size="16" /> Upload files…</label>
                                @endif
                            </div>
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

                <ul class="finder-grid" aria-label="{{ $folder ? 'Contents of '.$folder->name : 'Files' }}">
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
                        <li>
                            <a href="{{ route('files.preview', $file) }}" class="finder-item" title="{{ $file->name }}">
                                @include('files.partials.file-icon', ['file' => $file])
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
