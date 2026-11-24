@extends('layouts.app')

@section('title', 'Access · '.$folder->name)

@php
    $rule = fn (string $column, int $id) => $folder->access->firstWhere($column, $id);
    $abilities = \App\Models\FolderAccess::ABILITIES;
@endphp

@section('content')
    <a href="{{ route('files.folder', $folder) }}" class="back-link"><x-icon name="arrow-left" :size="16" /> {{ $folder->name }}</a>

    <div class="page-head">
        <div>
            <h2 class="section-title">Who has access to “{{ $folder->name }}”</h2>
            <p class="page-sub">Applies to this folder and everything in it. With nothing ticked, everyone allowed to use the library can use it. People also need the matching “Files (library)” permission from their tags.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('files.access.update', $folder) }}" class="narrow-form">
        @csrf
        @method('PUT')
        @foreach (['departments' => [$departments, 'department_id', 'Departments'], 'tags' => [$tags, 'tag_id', 'Tags']] as $group => [$items, $column, $heading])
            <div class="card table-scroll">
                <table class="access-table permission-grid">
                    <thead>
                        <tr><th scope="col">{{ $heading }}</th>@foreach ($abilities as $label)<th scope="col">{{ $label }}</th>@endforeach</tr>
                    </thead>
                    <tbody>
                        @forelse ($items as $item)
                            <tr data-permission-row>
                                <th scope="row">{{ $item->name }}</th>
                                @foreach ($abilities as $ability => $label)
                                    <td><input type="checkbox" name="{{ $group }}[{{ $item->id }}][]" value="{{ $ability }}" aria-label="{{ $item->name }}: {{ $label }}"
                                               @if ($ability === 'view') data-permission-view @endif
                                               @checked(in_array($ability, $rule($column, $item->id)?->abilities ?? [], true))></td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="5" class="muted">None yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endforeach
        <div class="form-actions">
            <a href="{{ route('files.folder', $folder) }}" class="btn">Cancel</a>
            <button type="submit" class="btn btn-primary">Save access</button>
        </div>
    </form>
@endsection
