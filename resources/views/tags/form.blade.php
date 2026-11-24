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
                    <input type="text" name="name" value="{{ old('name', $tag->name) }}" required maxlength="40" @readonly($tag->is_everyone) @error('name') aria-invalid="true" @enderror>
                    @if ($tag->is_everyone) <small class="muted">Built in: everyone has this tag. Its name can’t change.</small> @endif
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </label>
                <label class="field">
                    <span>Description</span>
                    <input type="text" name="description" value="{{ old('description', $tag->description) }}" maxlength="160">
                    @error('description') <p class="field-error">{{ $message }}</p> @enderror
                </label>
            </div>

            @php $granted = old('permissions', $tag->permissions ?? []); @endphp
            <fieldset class="permission-grid-wrap">
                <legend>What this tag allows, page by page</legend>
                <p class="muted small">Ticking anything on a page also ticks View, since the page has to open first.</p>
                <div class="table-scroll">
                    <table class="access-table permission-grid">
                        <thead>
                            <tr>
                                <th scope="col">Page</th>
                                @foreach (\App\Support\PagePermissions::STANDARD as $label)
                                    <th scope="col">{{ $label }}</th>
                                @endforeach
                                <th scope="col">Also</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach (\App\Support\PagePermissions::PAGES as $page => $definition)
                                <tr data-permission-row>
                                    <th scope="row">{{ $definition['label'] }}</th>
                                    @foreach (\App\Support\PagePermissions::STANDARD as $ability => $label)
                                        <td>
                                            @if (in_array($ability, $definition['abilities'], true))
                                                <input type="checkbox" name="permissions[]" value="{{ $page }}.{{ $ability }}"
                                                       aria-label="{{ $definition['label'] }}: {{ $label }}"
                                                       @if ($ability === 'view') data-permission-view @endif
                                                       @checked(in_array("{$page}.{$ability}", $granted, true))>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td>
                                        @foreach ($definition['extras'] ?? [] as $ability => $label)
                                            <label class="check">
                                                <input type="checkbox" name="permissions[]" value="{{ $page }}.{{ $ability }}" @checked(in_array("{$page}.{$ability}", $granted, true))>
                                                {{ $label }}
                                            </label>
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @error('permissions') <p class="field-error">{{ $message }}</p> @enderror
                @error('permissions.*') <p class="field-error">{{ $message }}</p> @enderror
            </fieldset>
        </div>

        <div class="form-actions">
            <a href="{{ route('tags.index') }}" class="btn">Cancel</a>
            <button type="submit" class="btn btn-primary">{{ $tag->exists ? 'Save tag' : 'Create tag' }}</button>
        </div>
    </form>

    @if ($tag->exists && ! $tag->is_everyone && auth()->user()->can('tags.delete'))
        <form method="POST" action="{{ route('tags.destroy', $tag) }}" data-confirm="Delete the tag “{{ $tag->name }}”? Everyone who has it loses its permissions." class="narrow-form">
            @csrf
            @method('DELETE')
            <div><button type="submit" class="btn btn-ghost-danger">Delete tag</button></div>
        </form>
    @endif
@endsection
