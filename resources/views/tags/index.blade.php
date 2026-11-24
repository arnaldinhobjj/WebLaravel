@extends('layouts.app')

@section('title', 'Tags')

@php
    use App\Support\PagePermissions;

    /** "Tickets: View, Reply · People: View" for a list of permissions. */
    $describe = fn (array $permissions): string => collect(PagePermissions::summary($permissions))
        ->map(fn (array $abilities, string $page): string => $page.': '.implode(', ', $abilities))
        ->join(' · ');
@endphp

@section('search')
    <label class="search">
        <x-icon name="search" :size="16" />
        <span class="sr-only">Search tags, departments and people</span>
        <input type="search" placeholder="Search tags, departments and people" autocomplete="off" data-search-input>
        <kbd>Ctrl K</kbd>
    </label>
@endsection

@section('content')
    <div class="page-head">
        <div>
            <h2 class="section-title">Tags <x-icon name="tag" :size="26" class="title-icon" /></h2>
            <p class="page-sub">Tags say what people may do on each page. Everyone has the Everyone tag; a department gives its tags to everyone in it; a person can also get extra tags.</p>
        </div>
    </div>

    {{-- Tags --}}
    @can('tags.view')
    <section class="access-section" aria-labelledby="tags-title" data-search-section>
        <div class="page-head">
            <h3 id="tags-title" class="sub-title">Tags</h3>
            @can('tags.create')
                <a href="{{ route('tags.create') }}" class="btn btn-primary"><x-icon name="plus" :size="16" /> New tag</a>
            @endcan
        </div>
        <div class="card table-scroll">
            <table class="access-table">
                <thead><tr><th>Tag</th><th>Allows</th><th>Departments</th><th>People (directly)</th></tr></thead>
                <tbody>
                    @foreach ($tags as $tag)
                        <tr data-search-item="{{ $tag->name }} {{ $tag->description }} {{ $describe($tag->permissions ?? []) }} {{ $tag->departments->pluck('name')->join(' ') }}">
                            <td>
                                @can('tags.edit')
                                    <a href="{{ route('tags.edit', $tag) }}"><strong>{{ $tag->name }}</strong></a>
                                @else
                                    <strong>{{ $tag->name }}</strong>
                                @endcan
                                @if ($tag->is_everyone) <span class="pill status-open">Built in</span> @endif
                                @if ($tag->description)<br><span class="muted small">{{ $tag->description }}</span>@endif
                            </td>
                            <td>
                                @forelse (PagePermissions::summary($tag->permissions ?? []) as $page => $abilities)
                                    <div><strong>{{ $page }}:</strong> {{ implode(', ', $abilities) }}</div>
                                @empty
                                    <span class="muted">Nothing yet</span>
                                @endforelse
                            </td>
                            <td>{{ $tag->is_everyone ? 'All' : ($tag->departments->pluck('name')->join(', ') ?: '—') }}</td>
                            <td>{{ $tag->is_everyone ? 'Everyone' : $tag->users_count }}</td>
                        </tr>
                    @endforeach
                    <tr data-search-empty hidden><td colspan="4" class="muted">No tags match your search.</td></tr>
                </tbody>
            </table>
        </div>
    </section>
    @endcan

    {{-- Departments --}}
    @can('departments.view')
    <section class="access-section" aria-labelledby="departments-title" data-search-section>
        <div class="page-head">
            <h3 id="departments-title" class="sub-title">Departments</h3>
            @can('departments.create')
                <a href="{{ route('departments.create') }}" class="btn"><x-icon name="plus" :size="16" /> New department</a>
            @endcan
        </div>
        <div class="card table-scroll">
            <table class="access-table">
                <thead><tr><th>Department</th><th>Tags everyone in it gets</th><th>People</th></tr></thead>
                <tbody>
                    @forelse ($departments as $department)
                        <tr data-search-item="{{ $department->name }} {{ $department->tags->pluck('name')->join(' ') }} {{ $department->users->pluck('name')->join(' ') }}">
                            <td>
                                @can('departments.edit')
                                    <a href="{{ route('departments.edit', $department) }}"><strong>{{ $department->name }}</strong></a>
                                @else
                                    <strong>{{ $department->name }}</strong>
                                @endcan
                            </td>
                            <td>
                                @forelse ($department->tags as $tag)<span class="pill">{{ $tag->name }}</span>@empty<span class="muted">None</span>@endforelse
                            </td>
                            <td>{{ $department->users->pluck('name')->join(', ') ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="muted">No departments yet.</td></tr>
                    @endforelse
                    <tr data-search-empty hidden><td colspan="3" class="muted">No departments match your search.</td></tr>
                </tbody>
            </table>
        </div>
    </section>
    @endcan

    {{-- People --}}
    @can('people.view')
    <section class="access-section" aria-labelledby="people-title" data-search-section>
        <div class="page-head">
            <h3 id="people-title" class="sub-title">People</h3>
            @can('people.create')
                <a href="{{ route('people.create') }}" class="btn"><x-icon name="plus" :size="16" /> Add person</a>
            @endcan
        </div>
        <div class="card table-scroll">
            <table class="access-table">
                <thead><tr><th>Person</th><th>Department</th><th>Extra tags</th><th>Can (with the Everyone tag)</th></tr></thead>
                <tbody>
                    @foreach ($people as $person)
                        <tr data-search-item="{{ $person->name }} {{ $person->email }} {{ $person->username }} {{ $person->job_title }} {{ $person->department?->name }} {{ $person->tags->pluck('name')->join(' ') }} {{ $describe($person->permissions()) }}">
                            <td>
                                @can('people.edit')
                                    <a href="{{ route('people.edit', $person) }}"><strong>{{ $person->name }}</strong></a>
                                @else
                                    <strong>{{ $person->name }}</strong>
                                @endcan
                                <br><span class="muted small">{{ $person->email }}</span>
                            </td>
                            <td>{{ $person->department?->name ?? '—' }}</td>
                            <td>
                                @forelse ($person->tags as $tag)<span class="pill">{{ $tag->name }}</span>@empty<span class="muted">—</span>@endforelse
                            </td>
                            <td>
                                @forelse (PagePermissions::summary($person->permissions()) as $page => $abilities)
                                    <div><strong>{{ $page }}:</strong> {{ implode(', ', $abilities) }}</div>
                                @empty
                                    <span class="muted">Only their home screen and settings</span>
                                @endforelse
                            </td>
                        </tr>
                    @endforeach
                    <tr data-search-empty hidden><td colspan="4" class="muted">No people match your search.</td></tr>
                </tbody>
            </table>
        </div>
    </section>
    @endcan
@endsection
