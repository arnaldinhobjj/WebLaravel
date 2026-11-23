@extends('layouts.app')

@section('title', $tag->exists ? $tag->name : 'New tag')

@section('content')
    <a href="{{ route('tags.index') }}" class="back-link"><x-icon name="arrow-left" :size="16" /> Tags</a>

    <div class="page-head">
        <h2 class="section-title">{{ $tag->exists ? 'Edit tag' : 'New tag' }}</h2>
    </div>

    <form method="POST" action="{{ $tag->exists ? route('tags.update', $tag) : route('tags.store') }}" class="narrow-form">
        @csrf
        @if ($tag->exists) @method('PUT') @endif

        <div class="card">
            <div class="field-row field-row-2">
                <label class="field">
                    <span>Name</span>
                    <input type="text" name="name" value="{{ old('name', $tag->name) }}" required maxlength="40" @error('name') aria-invalid="true" @enderror>
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </label>
                <label class="field">
                    <span>Description</span>
                    <input type="text" name="description" value="{{ old('description', $tag->description) }}" maxlength="160">
                    @error('description') <p class="field-error">{{ $message }}</p> @enderror
                </label>
            </div>

            <fieldset class="check-list">
                <legend>Allows</legend>
                @foreach (\App\Enums\Permission::cases() as $permission)
                    <label class="check">
                        <input type="checkbox" name="permissions[]" value="{{ $permission->value }}" @checked(in_array($permission->value, old('permissions', $tag->permissions ?? []), true))>
                        <span>{{ $permission->label() }}<small>{{ $permission->description() }}</small></span>
                    </label>
                @endforeach
                @error('permissions') <p class="field-error">{{ $message }}</p> @enderror
            </fieldset>
        </div>

        <div class="form-actions">
            <a href="{{ route('tags.index') }}" class="btn">Cancel</a>
            <button type="submit" class="btn btn-primary">{{ $tag->exists ? 'Save tag' : 'Create tag' }}</button>
        </div>
    </form>

    @if ($tag->exists)
        <form method="POST" action="{{ route('tags.destroy', $tag) }}" data-confirm="Delete the tag “{{ $tag->name }}”? Everyone who has it loses its permissions." class="narrow-form">
            @csrf
            @method('DELETE')
            <div><button type="submit" class="btn btn-ghost-danger">Delete tag</button></div>
        </form>
    @endif
@endsection
