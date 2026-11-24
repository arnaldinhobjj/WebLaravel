@extends('layouts.app')

@section('title', 'New ticket')

@section('search')
    @include('tickets.partials.search')
@endsection

@section('content')
    <a href="{{ route('tickets.index') }}" class="back-link"><x-icon name="arrow-left" :size="16" /> Tickets</a>

    <div class="page-head">
        <div>
            <h2 class="section-title">New ticket</h2>
            <p class="page-sub">Describe the problem or request. You can change any of this later.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('tickets.store') }}" class="card ticket-form" novalidate>
        @csrf

        <label class="field">
            <span>Subject</span>
            <input type="text" name="subject" maxlength="160" required autofocus
                   value="{{ old('subject', $prefill['subject'] ?? '') }}" @error('subject') aria-invalid="true" @enderror>
            @error('subject') <p class="field-error">{{ $message }}</p> @enderror
        </label>

        <div class="field-row">
            <label class="field">
                <span>Category</span>
                <select name="category" required>
                    @foreach (\App\Models\Ticket::CATEGORIES as $value => $label)
                        <option value="{{ $value }}" @selected(old('category', $prefill['category'] ?? 'general') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('category') <p class="field-error">{{ $message }}</p> @enderror
            </label>

            <label class="field">
                <span>Priority</span>
                <select name="priority" required>
                    @foreach (\App\Enums\TicketPriority::cases() as $case)
                        <option value="{{ $case->value }}" @selected(old('priority', $prefill['priority'] ?? 'normal') === $case->value)>{{ $case->label() }}</option>
                    @endforeach
                </select>
                @error('priority') <p class="field-error">{{ $message }}</p> @enderror
            </label>

            @can('tickets.assign')
            <label class="field">
                <span>Assignee <small>(optional)</small></span>
                <select name="assignee_id">
                    <option value="">Unassigned</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}" @selected((string) old('assignee_id') === (string) $user->id)>{{ $user->name }}</option>
                    @endforeach
                </select>
                @error('assignee_id') <p class="field-error">{{ $message }}</p> @enderror
            </label>
            @endcan
        </div>

        <label class="field">
            <span>Description</span>
            <textarea name="description" rows="8" required placeholder="What happened, where, and what you need." @error('description') aria-invalid="true" @enderror>{{ old('description', $prefill['description'] ?? '') }}</textarea>
            @error('description') <p class="field-error">{{ $message }}</p> @enderror
        </label>

        <div class="form-actions">
            <a href="{{ route('tickets.index') }}" class="btn">Cancel</a>
            <button type="submit" class="btn btn-primary"><x-icon name="plus" :size="16" /> Create ticket</button>
        </div>
    </form>
@endsection
