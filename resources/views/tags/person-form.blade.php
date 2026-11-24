@extends('layouts.app')

@section('title', $person->exists ? $person->name : 'Add person')

@section('content')
    <a href="{{ route('tags.index') }}" class="back-link"><x-icon name="arrow-left" :size="16" /> Tags</a>

    <div class="page-head">
        <div>
            <h2 class="section-title">{{ $person->exists ? $person->name : 'Add person' }}</h2>
            @if ($person->exists)
                <p class="page-sub">Can, including the Everyone tag: {{ collect(\App\Support\PagePermissions::summary($person->permissions()))->map(fn ($abilities, $page) => $page.': '.implode(', ', $abilities))->join(' · ') ?: 'nothing beyond their home screen and settings' }}.</p>
            @endif
        </div>
    </div>

    <form method="POST" action="{{ $person->exists ? route('people.update', $person) : route('people.store') }}" class="narrow-form">
        @csrf
        @if ($person->exists) @method('PUT') @endif

        <div class="card">
            <div class="field-row field-row-2">
                <label class="field">
                    <span>Name</span>
                    <input type="text" name="name" value="{{ old('name', $person->name) }}" required maxlength="80" @error('name') aria-invalid="true" @enderror>
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </label>
                <label class="field">
                    <span>Job title</span>
                    <input type="text" name="job_title" value="{{ old('job_title', $person->job_title) }}" maxlength="80">
                    @error('job_title') <p class="field-error">{{ $message }}</p> @enderror
                </label>
                <label class="field">
                    <span>Email (used to sign in)</span>
                    <input type="email" name="email" value="{{ old('email', $person->email) }}" required maxlength="255" @error('email') aria-invalid="true" @enderror>
                    @error('email') <p class="field-error">{{ $message }}</p> @enderror
                </label>
                <label class="field">
                    <span>{{ $person->exists ? 'New password (leave empty to keep it)' : 'Password' }}</span>
                    <input type="password" name="password" minlength="8" autocomplete="new-password" @unless ($person->exists) required @endunless @error('password') aria-invalid="true" @enderror>
                    @error('password') <p class="field-error">{{ $message }}</p> @enderror
                </label>
                <label class="field">
                    <span>Department</span>
                    <select name="department_id">
                        <option value="">No department</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}" @selected((int) old('department_id', $person->department_id) === $department->id)>
                                {{ $department->name }}@if ($department->tags->isNotEmpty()) ({{ $department->tags->pluck('name')->join(', ') }})@endif
                            </option>
                        @endforeach
                    </select>
                    @error('department_id') <p class="field-error">{{ $message }}</p> @enderror
                </label>
            </div>

            <fieldset class="check-list">
                <legend>Extra tags, on top of their department’s</legend>
                @php $selected = array_map('intval', old('tags', $person->exists ? $person->tags->modelKeys() : [])); @endphp
                @forelse ($tags as $tag)
                    <label class="check">
                        <input type="checkbox" name="tags[]" value="{{ $tag->id }}" @checked(in_array($tag->id, $selected, true))>
                        <span>{{ $tag->name }}<small>{{ collect(\App\Support\PagePermissions::summary($tag->permissions ?? []))->map(fn ($abilities, $page) => $page.': '.implode(', ', $abilities))->join(' · ') ?: 'Grants nothing yet' }}</small></span>
                    </label>
                @empty
                    <p class="muted">No tags yet.</p>
                @endforelse
                @error('tags') <p class="field-error">{{ $message }}</p> @enderror
            </fieldset>
        </div>

        <div class="form-actions">
            <a href="{{ route('tags.index') }}" class="btn">Cancel</a>
            <button type="submit" class="btn btn-primary">{{ $person->exists ? 'Save' : 'Add person' }}</button>
        </div>
    </form>
@endsection
