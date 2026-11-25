<label class="field">
    <span>Name</span>
    <input type="text" name="name" value="{{ old('name', $dashboard->name) }}" required maxlength="60" @error('name') aria-invalid="true" @enderror>
    @error('name') <p class="field-error">{{ $message }}</p> @enderror
</label>

<fieldset class="field">
    <legend>Who can see it</legend>
    @foreach (\App\Enums\DashboardVisibility::cases() as $visibility)
        @continue($visibility === \App\Enums\DashboardVisibility::Shared && ! auth()->user()->can('dashboards.share') && ! $dashboard->isShared())
        <label class="check">
            <input type="radio" name="visibility" value="{{ $visibility->value }}" @checked(old('visibility', $dashboard->visibility?->value ?? 'personal') === $visibility->value)>
            {{ $visibility->label() }}
        </label>
    @endforeach
    @error('visibility') <p class="field-error">{{ $message }}</p> @enderror
</fieldset>
