@extends('layouts.app')

@section('title', 'Tickets')

@section('search')
    @include('tickets.partials.search')
@endsection

@php
    $tabs = ['active' => ['Active', $activeCount]];
    foreach (\App\Enums\TicketStatus::cases() as $case) {
        $tabs[$case->value] = [$case->label(), $counts[$case->value] ?? 0];
    }
    $tabs['all'] = ['All', $counts->sum()];
    $query = fn (array $changes) => array_filter(array_merge($filters, $changes), fn ($v) => $v !== null && $v !== '');
    $hasFilters = collect($filters)->except(['status', 'sort'])->filter()->isNotEmpty();
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h2 class="section-title">Tickets <x-icon name="ticket" :size="26" class="title-icon" /></h2>
            <p class="page-sub">
                @if (filter_var($filters['resolved_within'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 365]]))
                    Resolved or closed in the last {{ (int) $filters['resolved_within'] }} days
                @elseif (($filters['assignee'] ?? null) === 'me')
                    Tickets assigned to you
                @elseif (($filters['requester'] ?? null) === 'me')
                    Tickets you opened
                @else
                    Requests from across Fort Vale
                @endif
            </p>
        </div>
        @can('tickets.create')
            <a href="{{ route('tickets.create') }}" class="btn btn-primary"><x-icon name="plus" :size="16" /> New ticket</a>
        @endcan
    </div>

    <nav class="tabs" aria-label="Filter by status">
        @foreach ($tabs as $key => [$label, $count])
            <a href="{{ route('tickets.index', $query(['status' => $key === 'active' ? null : $key])) }}"
               @class(['tab', 'is-active' => $status === $key]) @if ($status === $key) aria-current="page" @endif>
                {{ $label }} <span class="tab-count">{{ $count }}</span>
            </a>
        @endforeach
    </nav>

    <form method="GET" action="{{ route('tickets.index') }}" class="filters" data-auto-submit>
        @foreach (['q', 'status', 'requester', 'resolved_within'] as $keep)
            @if (! empty($filters[$keep]))
                <input type="hidden" name="{{ $keep }}" value="{{ $filters[$keep] }}">
            @endif
        @endforeach

        <label class="field-inline">
            <span>Priority</span>
            <select name="priority">
                <option value="">Any</option>
                @foreach (\App\Enums\TicketPriority::cases() as $priority)
                    <option value="{{ $priority->value }}" @selected(($filters['priority'] ?? '') === $priority->value)>{{ $priority->label() }}</option>
                @endforeach
            </select>
        </label>

        <label class="field-inline">
            <span>Category</span>
            <select name="category">
                <option value="">Any</option>
                @foreach (\App\Models\Ticket::CATEGORIES as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['category'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>

        <label class="field-inline">
            <span>Assignee</span>
            <select name="assignee">
                <option value="">Anyone</option>
                <option value="me" @selected(($filters['assignee'] ?? '') === 'me')>Me</option>
                <option value="unassigned" @selected(($filters['assignee'] ?? '') === 'unassigned')>Unassigned</option>
                @foreach ($users as $user)
                    <option value="{{ $user->id }}" @selected(($filters['assignee'] ?? '') === (string) $user->id)>{{ $user->name }}</option>
                @endforeach
            </select>
        </label>

        <label class="field-inline">
            <span>Sort</span>
            <select name="sort">
                <option value="updated" @selected(($filters['sort'] ?? 'updated') === 'updated')>Recently updated</option>
                <option value="priority" @selected(($filters['sort'] ?? '') === 'priority')>Priority</option>
                <option value="newest" @selected(($filters['sort'] ?? '') === 'newest')>Newest</option>
                <option value="oldest" @selected(($filters['sort'] ?? '') === 'oldest')>Oldest</option>
            </select>
        </label>

        <noscript><button type="submit" class="btn">Apply</button></noscript>

        @if ($hasFilters)
            <a href="{{ route('tickets.index', array_filter(['status' => $filters['status'] ?? null])) }}" class="link-btn">Clear filters</a>
        @endif
    </form>

    @if ($tickets->isEmpty())
        <div class="empty-state">
            <x-icon name="inbox" :size="40" />
            <p><strong>No tickets here.</strong></p>
            <p>{{ $hasFilters ? 'Try changing or clearing the filters.' : 'Nothing in this list right now.' }}</p>
        </div>
    @else
        <div class="ticket-table" role="table" aria-label="Tickets">
            <div class="ticket-row ticket-row-head" role="row">
                <span role="columnheader">Ticket</span>
                <span role="columnheader">Requester</span>
                <span role="columnheader">Assignee</span>
                <span role="columnheader">Priority</span>
                <span role="columnheader">Status</span>
                <span role="columnheader">Updated</span>
            </div>
            @foreach ($tickets as $ticket)
                <a href="{{ route('tickets.show', $ticket) }}" class="ticket-row" role="row">
                    <span class="ticket-main" role="cell">
                        <span class="ticket-id">#{{ $ticket->id }}</span>
                        <span class="ticket-subject">{{ $ticket->subject }}</span>
                        <span class="ticket-meta">
                            {{ $ticket->categoryLabel() }}
                            @if ($ticket->comments_count)
                                · <x-icon name="message" :size="13" class="inline-icon" /> {{ $ticket->comments_count }}
                            @endif
                        </span>
                    </span>
                    <span role="cell" class="cell-person" data-label="Requester">{{ $ticket->requester->name }}</span>
                    <span role="cell" class="cell-person" data-label="Assignee">
                        @if ($ticket->assignee)
                            <span class="avatar-initials avatar-sm">{{ $ticket->assignee->initials() }}</span> {{ $ticket->assignee->name }}
                        @else
                            <span class="muted">Unassigned</span>
                        @endif
                    </span>
                    <span role="cell"><x-priority-pill :priority="$ticket->priority" /></span>
                    <span role="cell"><x-status-pill :status="$ticket->status" /></span>
                    <span role="cell" class="muted cell-time"><time datetime="{{ $ticket->updated_at->toIso8601String() }}" title="{{ $ticket->updated_at->format('j M Y, H:i') }}">{{ $ticket->updated_at->diffForHumans(short: true) }}</time></span>
                </a>
            @endforeach
        </div>

        {{ $tickets->links() }}
    @endif
@endsection
