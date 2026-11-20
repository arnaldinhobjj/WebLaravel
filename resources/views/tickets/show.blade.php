@extends('layouts.app')

@section('title', "#{$ticket->id} {$ticket->subject}")

@section('search')
    @include('tickets.partials.search')
@endsection

@section('content')
    <a href="{{ url()->previous() !== url()->current() && str_contains(url()->previous(), '/ticket') ? url()->previous() : route('tickets.index') }}" class="back-link">
        <x-icon name="arrow-left" :size="16" /> Tickets
    </a>

    <div class="ticket-layout">
        <article class="ticket-detail">
            <header class="ticket-header">
                <div class="ticket-header-pills">
                    <span class="ticket-id">#{{ $ticket->id }}</span>
                    <x-status-pill :status="$ticket->status" />
                    <x-priority-pill :priority="$ticket->priority" />
                </div>
                <h2 class="ticket-title">{{ $ticket->subject }}</h2>
                <p class="page-sub">
                    Opened by <strong>{{ $ticket->requester->name }}</strong>
                    <time datetime="{{ $ticket->created_at->toIso8601String() }}" title="{{ $ticket->created_at->format('j M Y, H:i') }}">{{ $ticket->created_at->diffForHumans() }}</time>
                    in {{ $ticket->categoryLabel() }}
                </p>
            </header>

            <div class="card ticket-description">
                <div class="comment-head">
                    <span class="avatar-initials avatar-sm">{{ $ticket->requester->initials() }}</span>
                    <strong>{{ $ticket->requester->name }}</strong>
                    <span class="muted">{{ $ticket->created_at->format('j M Y, H:i') }}</span>
                </div>
                <div class="prose">{!! nl2br(e($ticket->description)) !!}</div>
            </div>

            <h3 class="sub-title">Activity</h3>
            <ol class="timeline">
                @foreach ($ticket->activities as $activity)
                    @continue($activity->type === 'created')
                    <li @class(['timeline-item', 'is-comment' => $activity->type === 'comment']) @if ($loop->last) id="latest" @endif>
                        @if ($activity->type === 'comment')
                            <div class="card comment">
                                <div class="comment-head">
                                    <span class="avatar-initials avatar-sm">{{ $activity->user?->initials() ?? '?' }}</span>
                                    <strong>{{ $activity->user?->name ?? 'Someone' }}</strong>
                                    <time class="muted" datetime="{{ $activity->created_at->toIso8601String() }}" title="{{ $activity->created_at->format('j M Y, H:i') }}">{{ $activity->created_at->diffForHumans() }}</time>
                                </div>
                                <div class="prose">{!! nl2br(e($activity->body)) !!}</div>
                            </div>
                        @else
                            <p class="event">
                                <x-icon name="clock" :size="14" />
                                <span><strong>{{ $activity->user?->name ?? 'Someone' }}</strong> {{ $activity->summary() }}</span>
                                <time class="muted" datetime="{{ $activity->created_at->toIso8601String() }}" title="{{ $activity->created_at->format('j M Y, H:i') }}">{{ $activity->created_at->diffForHumans() }}</time>
                            </p>
                        @endif
                    </li>
                @endforeach
            </ol>

            <form method="POST" action="{{ route('tickets.comment', $ticket) }}" class="card reply">
                @csrf
                <label for="reply-body" class="reply-label">
                    <span class="avatar-initials avatar-sm">{{ $me->initials() }}</span> Reply as {{ $me->name }}
                </label>
                <textarea id="reply-body" name="body" rows="4" required placeholder="Write a reply…" @error('body') aria-invalid="true" @enderror>{{ old('body') }}</textarea>
                @error('body') <p class="field-error">{{ $message }}</p> @enderror
                <div class="reply-actions">
                    <label class="field-inline">
                        <span>and set status to</span>
                        <select name="status">
                            <option value="">Keep {{ $ticket->status->label() }}</option>
                            @foreach (\App\Enums\TicketStatus::cases() as $case)
                                @continue($case === $ticket->status)
                                <option value="{{ $case->value }}">{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button type="submit" class="btn btn-primary"><x-icon name="message" :size="16" /> Post reply</button>
                </div>
            </form>
        </article>

        <aside class="ticket-sidebar">
            <div class="card">
                <h3 class="sub-title">Details</h3>
                <form method="POST" action="{{ route('tickets.update', $ticket) }}" class="details-form">
                    @csrf
                    @method('PATCH')

                    <label class="field">
                        <span>Status</span>
                        <select name="status">
                            @foreach (\App\Enums\TicketStatus::cases() as $case)
                                <option value="{{ $case->value }}" @selected($ticket->status === $case)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="field">
                        <span>Priority</span>
                        <select name="priority">
                            @foreach (\App\Enums\TicketPriority::cases() as $case)
                                <option value="{{ $case->value }}" @selected($ticket->priority === $case)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="field">
                        <span>Assignee</span>
                        <select name="assignee_id">
                            <option value="">Unassigned</option>
                            @foreach ($users as $user)
                                <option value="{{ $user->id }}" @selected($ticket->assignee_id === $user->id)>{{ $user->name }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="field">
                        <span>Category</span>
                        <select name="category">
                            @foreach (\App\Models\Ticket::CATEGORIES as $value => $label)
                                <option value="{{ $value }}" @selected($ticket->category === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <button type="submit" class="btn btn-block">Save changes</button>
                </form>
            </div>

            <div class="card quick-actions">
                @if ($ticket->assignee_id !== $me->id)
                    <form method="POST" action="{{ route('tickets.update', $ticket) }}">
                        @csrf @method('PATCH')
                        <input type="hidden" name="assignee_id" value="{{ $me->id }}">
                        <button type="submit" class="btn btn-block">Assign to me</button>
                    </form>
                @endif
                <form method="POST" action="{{ route('tickets.update', $ticket) }}">
                    @csrf @method('PATCH')
                    @if ($ticket->status->isActive())
                        <input type="hidden" name="status" value="resolved">
                        <button type="submit" class="btn btn-block btn-success"><x-icon name="check" :size="16" /> Mark resolved</button>
                    @else
                        <input type="hidden" name="status" value="open">
                        <button type="submit" class="btn btn-block"><x-icon name="rotate" :size="16" /> Reopen</button>
                    @endif
                </form>
            </div>

            <dl class="card facts">
                <div><dt>Requester</dt><dd>{{ $ticket->requester->name }}</dd></div>
                <div><dt>Created</dt><dd>{{ $ticket->created_at->format('j M Y, H:i') }}</dd></div>
                <div><dt>Last update</dt><dd>{{ $ticket->updated_at->diffForHumans() }}</dd></div>
                @if ($ticket->resolved_at)
                    <div><dt>{{ $ticket->status->label() }}</dt><dd>{{ $ticket->resolved_at->format('j M Y, H:i') }}</dd></div>
                @endif
            </dl>
        </aside>
    </div>
@endsection
