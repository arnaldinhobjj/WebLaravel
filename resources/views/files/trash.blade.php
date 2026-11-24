@extends('layouts.app')

@section('title', $mine ? 'My trash' : 'Library trash')

@section('content')
    <div class="files-layout">
        @include('files.partials.nav', ['area' => $mine ? 'trash-mine' : 'trash'])
        <div class="files-main">
            <div class="page-head">
                <div>
                    <h2 class="section-title">{{ $mine ? 'My trash' : 'Library trash' }}</h2>
                    <p class="page-sub">Deleted files and folders stay here for {{ \App\Services\Files\FileStore::TRASH_DAYS }} days, then they’re gone for good.</p>
                </div>
                @if ($canEmpty && ($folders->isNotEmpty() || $files->isNotEmpty()))
                    <form method="POST" action="{{ route('files.trash.empty') }}" data-confirm="Delete everything in this trash for good? This can’t be undone.">
                        @csrf
                        <input type="hidden" name="area" value="{{ $mine ? 'mine' : 'library' }}">
                        <button type="submit" class="btn btn-ghost-danger">Empty trash</button>
                    </form>
                @endif
            </div>

            @if ($folders->isEmpty() && $files->isEmpty())
                <p class="muted">The trash is empty.</p>
            @else
                <div class="card table-scroll">
                    <table class="access-table">
                        <thead><tr><th>Name</th><th>Was in</th><th>Deleted</th><th><span class="sr-only">Actions</span></th></tr></thead>
                        <tbody>
                            @foreach ($folders as $item)
                                <tr>
                                    <td><strong>{{ $item->name }}</strong> <span class="muted small">folder</span></td>
                                    <td class="muted">{{ $item->parent?->path() }}</td>
                                    <td class="muted">{{ $item->deleted_at->diffForHumans() }}</td>
                                    <td><form method="POST" action="{{ route('files.trash.restore', ['folder', $item->id]) }}">@csrf<button type="submit" class="btn">Restore</button></form></td>
                                </tr>
                            @endforeach
                            @foreach ($files as $item)
                                <tr>
                                    <td>{{ $item->name }}</td>
                                    <td class="muted">{{ $item->folder?->path() }}</td>
                                    <td class="muted">{{ $item->deleted_at->diffForHumans() }}</td>
                                    <td><form method="POST" action="{{ route('files.trash.restore', ['file', $item->id]) }}">@csrf<button type="submit" class="btn">Restore</button></form></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
