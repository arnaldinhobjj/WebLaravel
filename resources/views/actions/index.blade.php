@extends('layouts.app')

@section('title', 'My actions')

@section('content')
    <div class="page-head">
        <div>
            <h2 class="section-title">My actions <x-icon name="workflow" :size="28" class="title-icon" /></h2>
            <p class="page-sub">Shortcuts you can run from here or from the Actions row on the home screen.</p>
        </div>
        <a href="{{ route('actions.create', $user) }}" class="btn btn-primary"><x-icon name="plus" :size="16" /> New action</a>
    </div>

    @if ($actions->isEmpty())
        <div class="empty-state action-empty">
            <x-icon name="workflow" :size="40" />
            <p><strong>You haven’t made any actions yet.</strong></p>
            <p>An action can open a page you visit often, or start a ticket you file often.</p>
            <a href="{{ route('actions.create', $user) }}" class="btn btn-primary"><x-icon name="plus" :size="16" /> Create your first action</a>
        </div>
    @else
        <ul class="action-cards">
            @foreach ($actions as $action)
                <li class="card action-card">
                    <div class="action-card-head">
                        <span class="action-card-icon"><x-icon :name="$action->type === \App\Enums\ActionType::Link ? 'link' : 'ticket'" :size="20" /></span>
                        <div class="action-card-title">
                            <h3>{{ $action->name }}</h3>
                            <p class="muted">{{ $action->type->label() }}</p>
                        </div>
                        @if ($action->show_on_home)
                            <span class="pill status-open">On home</span>
                        @else
                            <span class="pill">Hidden from home</span>
                        @endif
                    </div>

                    @if ($action->description)
                        <p class="action-card-description">{{ $action->description }}</p>
                    @endif

                    <p class="action-card-target muted">
                        @if ($action->type === \App\Enums\ActionType::Link)
                            Opens <code>{{ $action->url }}</code>
                        @else
                            New {{ \App\Models\Ticket::CATEGORIES[$action->ticket_category] ?? 'General' }} ticket:
                            “{{ $action->ticket_subject }}” · {{ $action->ticket_priority?->label() }}
                        @endif
                    </p>

                    <div class="action-card-foot">
                        <span class="muted small">{{ $action->last_run_at ? 'Last run '.$action->last_run_at->diffForHumans() : 'Never run' }}</span>
                        <div class="action-card-buttons">
                            <form method="POST" action="{{ route('actions.destroy', [$user, $action]) }}" data-confirm="Delete “{{ $action->name }}”?">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-ghost-danger">Delete</button>
                            </form>
                            <a href="{{ route('actions.edit', [$user, $action]) }}" class="btn">Edit</a>
                            <form method="POST" action="{{ route('actions.custom.run', [$user, $action]) }}" @if ($action->opensExternalSite()) target="_blank" @endif>
                                @csrf
                                <button type="submit" class="btn btn-primary"><x-icon name="play" :size="16" /> Run</button>
                            </form>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    <section class="builtin-actions" aria-labelledby="builtin-title">
        <h3 id="builtin-title" class="sub-title">Built-in actions</h3>
        <p class="muted">These are always on the home screen for everyone.</p>
        <ul class="builtin-list">
            @foreach (\App\Services\Workspace::ACTIONS as $builtin)
                <li><x-icon name="list-checks" :size="16" /> <strong>{{ $builtin['name'] }}</strong> <span class="muted">— {{ $builtin['description'] }}</span></li>
            @endforeach
        </ul>
    </section>
@endsection
