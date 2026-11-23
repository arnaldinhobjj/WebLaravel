@extends('layouts.app')

@section('title', 'Tags')

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
            <p class="page-sub">Tags hold permissions. Give a tag to a department and everyone in it gets it; give it to a person for something extra.</p>
        </div>
    </div>

    {{-- Tags --}}
    <section class="access-section" aria-labelledby="tags-title" data-search-section>
        <div class="page-head">
            <h3 id="tags-title" class="sub-title">Tags</h3>
            <a href="{{ route('tags.create') }}" class="btn btn-primary"><x-icon name="plus" :size="16" /> New tag</a>
        </div>
        <div class="card table-scroll">
            <table class="access-table">
                <thead><tr><th>Tag</th><th>Allows</th><th>Departments</th><th>People (directly)</th></tr></thead>
                <tbody>
                    @forelse ($tags as $tag)
                        <tr data-search-item="{{ $tag->name }} {{ $tag->description }} {{ collect(\App\Enums\Permission::cases())->filter(fn ($permission) => $tag->grants($permission))->map->label()->join(' ') }} {{ $tag->departments->pluck('name')->join(' ') }}">
                            <td><a href="{{ route('tags.edit', $tag) }}"><strong>{{ $tag->name }}</strong></a>@if ($tag->description)<br><span class="muted small">{{ $tag->description }}</span>@endif</td>
                            <td>
                                @foreach (\App\Enums\Permission::cases() as $permission)
                                    @if ($tag->grants($permission))<span class="pill">{{ $permission->label() }}</span>@endif
                                @endforeach
                                @if ($tag->permissions === [])<span class="muted">Nothing yet</span>@endif
                            </td>
                            <td>{{ $tag->departments->pluck('name')->join(', ') ?: '—' }}</td>
                            <td>{{ $tag->users_count }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="muted">No tags yet.</td></tr>
                    @endforelse
                    <tr data-search-empty hidden><td colspan="4" class="muted">No tags match your search.</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    {{-- Departments --}}
    <section class="access-section" aria-labelledby="departments-title" data-search-section>
        <div class="page-head">
            <h3 id="departments-title" class="sub-title">Departments</h3>
            <a href="{{ route('departments.create') }}" class="btn"><x-icon name="plus" :size="16" /> New department</a>
        </div>
        <div class="card table-scroll">
            <table class="access-table">
                <thead><tr><th>Department</th><th>Tags everyone in it gets</th><th>People</th></tr></thead>
                <tbody>
                    @forelse ($departments as $department)
                        <tr data-search-item="{{ $department->name }} {{ $department->tags->pluck('name')->join(' ') }} {{ $department->users->pluck('name')->join(' ') }}">
                            <td><a href="{{ route('departments.edit', $department) }}"><strong>{{ $department->name }}</strong></a></td>
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

    {{-- People --}}
    <section class="access-section" aria-labelledby="people-title" data-search-section>
        <div class="page-head">
            <h3 id="people-title" class="sub-title">People</h3>
            <a href="{{ route('people.create') }}" class="btn"><x-icon name="plus" :size="16" /> Add person</a>
        </div>
        <div class="card table-scroll">
            <table class="access-table">
                <thead><tr><th>Person</th><th>Department</th><th>Extra tags</th><th>Can</th></tr></thead>
                <tbody>
                    @foreach ($people as $person)
                        <tr data-search-item="{{ $person->name }} {{ $person->email }} {{ $person->username }} {{ $person->job_title }} {{ $person->department?->name }} {{ $person->tags->pluck('name')->join(' ') }} {{ collect($person->permissions())->map->label()->join(' ') }}">
                            <td><a href="{{ route('people.edit', $person) }}"><strong>{{ $person->name }}</strong></a><br><span class="muted small">{{ $person->email }}</span></td>
                            <td>{{ $person->department?->name ?? '—' }}</td>
                            <td>
                                @forelse ($person->tags as $tag)<span class="pill">{{ $tag->name }}</span>@empty<span class="muted">—</span>@endforelse
                            </td>
                            <td>
                                @forelse ($person->permissions() as $permission)<span class="pill status-open">{{ $permission->label() }}</span>@empty<span class="muted">Only their own tickets and actions</span>@endforelse
                            </td>
                        </tr>
                    @endforeach
                    <tr data-search-empty hidden><td colspan="4" class="muted">No people match your search.</td></tr>
                </tbody>
            </table>
        </div>
    </section>
@endsection
