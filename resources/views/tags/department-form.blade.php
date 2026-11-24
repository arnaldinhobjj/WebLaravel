@extends('layouts.app')

@section('title', $department->exists ? $department->name : 'New department')

@section('content')
    <a href="{{ route('tags.index') }}" class="back-link"><x-icon name="arrow-left" :size="16" /> Tags</a>

    <div class="page-head">
        <div>
            <h2 class="section-title">{{ $department->exists ? 'Edit department' : 'New department' }}</h2>
            @if ($department->exists)
                <p class="page-sub">{{ $department->users->count() }} {{ Str::plural('person', $department->users->count()) }}: {{ $department->users->pluck('name')->join(', ') ?: 'nobody yet' }}. Change someone’s department from their row under People.</p>
            @endif
        </div>
    </div>

    <form method="POST" action="{{ $department->exists ? route('departments.update', $department) : route('departments.store') }}" class="narrow-form">
        @csrf
        @if ($department->exists) @method('PUT') @endif

        <div class="card">
            <label class="field">
                <span>Name</span>
                <input type="text" name="name" value="{{ old('name', $department->name) }}" required maxlength="60" @error('name') aria-invalid="true" @enderror>
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
            </label>

            <fieldset class="check-list">
                <legend>Tags everyone in this department gets</legend>
                @php $selected = array_map('intval', old('tags', $department->exists ? $department->tags->modelKeys() : [])); @endphp
                @forelse ($tags as $tag)
                    <label class="check">
                        <input type="checkbox" name="tags[]" value="{{ $tag->id }}" @checked(in_array($tag->id, $selected, true))>
                        <span>{{ $tag->name }}<small>{{ collect(\App\Support\PagePermissions::summary($tag->permissions ?? []))->map(fn ($abilities, $page) => $page.': '.implode(', ', $abilities))->join(' · ') ?: 'Grants nothing yet' }}</small></span>
                    </label>
                @empty
                    <p class="muted">No tags yet. <a href="{{ route('tags.create') }}">Create one first.</a></p>
                @endforelse
                @error('tags') <p class="field-error">{{ $message }}</p> @enderror
            </fieldset>
        </div>

        <div class="form-actions">
            <a href="{{ route('tags.index') }}" class="btn">Cancel</a>
            <button type="submit" class="btn btn-primary">{{ $department->exists ? 'Save department' : 'Create department' }}</button>
        </div>
    </form>

    @if ($department->exists)
        <form method="POST" action="{{ route('departments.destroy', $department) }}" data-confirm="Delete “{{ $department->name }}”? Its people keep their accounts but lose its tags." class="narrow-form">
            @csrf
            @method('DELETE')
            <div><button type="submit" class="btn btn-ghost-danger">Delete department</button></div>
        </form>
    @endif
@endsection
