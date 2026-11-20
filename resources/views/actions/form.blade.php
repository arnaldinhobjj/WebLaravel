@extends('layouts.app')

@section('title', $action->exists ? 'Edit action' : 'New action')

@php
    $selectedType = old('type', $action->type?->value ?? 'link');
@endphp

@section('content')
    <a href="{{ route('actions.index', $user) }}" class="back-link"><x-icon name="arrow-left" :size="16" /> My actions</a>

    <div class="page-head">
        <div>
            <h2 class="section-title">{{ $action->exists ? 'Edit action' : 'New action' }}</h2>
            <p class="page-sub">Actions you show on the home screen appear in its Actions row.</p>
        </div>
    </div>

    <form method="POST" action="{{ $action->exists ? route('actions.update', [$user, $action]) : route('actions.store', $user) }}" class="card ticket-form" data-action-form novalidate>
        @csrf
        @if ($action->exists)
            @method('PUT')
        @endif

        <div class="field-row field-row-2">
            <label class="field">
                <span>Name</span>
                <input type="text" name="name" value="{{ old('name', $action->name) }}" required maxlength="60" autofocus placeholder="Report a broken camera" @error('name') aria-invalid="true" @enderror>
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
            </label>
            <label class="field">
                <span>Description <small>(optional)</small></span>
                <input type="text" name="description" value="{{ old('description', $action->description) }}" maxlength="160" placeholder="Shown when you hover over the action" @error('description') aria-invalid="true" @enderror>
                @error('description') <p class="field-error">{{ $message }}</p> @enderror
            </label>
        </div>

        <fieldset class="type-options">
            <legend class="field-legend">What should it do?</legend>
            @foreach (\App\Enums\ActionType::cases() as $type)
                <label class="type-option">
                    <input type="radio" name="type" value="{{ $type->value }}" @checked($selectedType === $type->value) data-type-choice>
                    <x-icon :name="$type === \App\Enums\ActionType::Link ? 'link' : 'ticket'" :size="20" />
                    <span>
                        <strong>{{ $type->label() }}</strong>
                        <small>{{ $type->hint() }}</small>
                    </span>
                </label>
            @endforeach
            @error('type') <p class="field-error">{{ $message }}</p> @enderror
        </fieldset>

        <div class="type-fields" data-type-fields="link" @if ($selectedType !== 'link') hidden @endif>
            <label class="field">
                <span>Page or web address</span>
                <input type="text" name="url" value="{{ old('url', $action->url) }}" maxlength="2048" placeholder="/ticket?assignee=me or https://…" @error('url') aria-invalid="true" @enderror>
                @error('url') <p class="field-error">{{ $message }}</p> @enderror
            </label>
        </div>

        <div class="type-fields" data-type-fields="ticket" @if ($selectedType !== 'ticket') hidden @endif>
            <label class="field">
                <span>Ticket subject</span>
                <input type="text" name="ticket_subject" value="{{ old('ticket_subject', $action->ticket_subject) }}" maxlength="160" @error('ticket_subject') aria-invalid="true" @enderror>
                @error('ticket_subject') <p class="field-error">{{ $message }}</p> @enderror
            </label>
            <div class="field-row field-row-2">
                <label class="field">
                    <span>Category</span>
                    <select name="ticket_category">
                        @foreach (\App\Models\Ticket::CATEGORIES as $value => $label)
                            <option value="{{ $value }}" @selected(old('ticket_category', $action->ticket_category ?? 'general') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('ticket_category') <p class="field-error">{{ $message }}</p> @enderror
                </label>
                <label class="field">
                    <span>Priority</span>
                    <select name="ticket_priority">
                        @foreach (\App\Enums\TicketPriority::cases() as $priority)
                            <option value="{{ $priority->value }}" @selected(old('ticket_priority', $action->ticket_priority?->value ?? 'normal') === $priority->value)>{{ $priority->label() }}</option>
                        @endforeach
                    </select>
                    @error('ticket_priority') <p class="field-error">{{ $message }}</p> @enderror
                </label>
            </div>
            <label class="field">
                <span>Description template <small>(optional)</small></span>
                <textarea name="ticket_description" rows="4" maxlength="5000" placeholder="Questions you want answered every time" @error('ticket_description') aria-invalid="true" @enderror>{{ old('ticket_description', $action->ticket_description) }}</textarea>
                @error('ticket_description') <p class="field-error">{{ $message }}</p> @enderror
            </label>
        </div>

        <label class="checkbox">
            <input type="hidden" name="show_on_home" value="0">
            <input type="checkbox" name="show_on_home" value="1" @checked(old('show_on_home', $action->show_on_home))>
            Show on the home screen
        </label>

        <div class="form-actions">
            <a href="{{ route('actions.index', $user) }}" class="btn">Cancel</a>
            <button type="submit" class="btn btn-primary">{{ $action->exists ? 'Save action' : 'Create action' }}</button>
        </div>
    </form>
@endsection
