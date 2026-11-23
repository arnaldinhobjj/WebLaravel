@extends('layouts.app')

@section('title', 'Home')

@section('content')
    {{-- Favorites --}}
    <section class="section" aria-labelledby="favorites-title" data-search-section data-carousel>
        <div class="section-head">
            <h2 class="section-title" id="favorites-title">
                Favorites <x-icon name="star" :size="22" class="title-icon star-icon" />
            </h2>
            <x-carousel-controls label="favorites" />
        </div>

        <div class="tiles carousel-track" data-carousel-track tabindex="-1">
            @foreach ($favorites as $slug => $module)
                <article class="tile" data-search-item="{{ $module['name'] }} {{ $module['description'] }}">
                    <a href="#" class="tile-link" title="{{ $module['description'] }}">
                        <x-icon :name="$module['icon']" :size="44" class="tile-icon" />
                        <span class="tile-name">{{ $module['name'] }}</span>
                    </a>
                    <form method="POST" action="{{ route('favorites.toggle', $slug) }}" class="tile-star">
                        @csrf
                        <button type="submit" title="Remove {{ $module['name'] }} from favorites" aria-pressed="true">
                            <x-icon name="star" :size="16" />
                            <span class="sr-only">Remove {{ $module['name'] }} from favorites</span>
                        </button>
                    </form>
                </article>
            @endforeach

            @if (count($otherModules))
                <div class="tile tile-add">
                    <button type="button" class="tile-link" data-dialog-open="add-favorite">
                        <x-icon name="plus" :size="32" class="tile-icon" />
                        <span class="tile-name">Add</span>
                    </button>
                </div>
            @endif
        </div>
        <p class="empty" data-search-empty hidden>No favorites match your search.</p>
    </section>

    {{-- Actions --}}
    <section class="section" aria-labelledby="actions-title" data-search-section data-carousel>
        <div class="section-head">
            <h2 class="section-title" id="actions-title">
                Actions <x-icon name="workflow" :size="30" class="title-icon" />
            </h2>
            <a href="{{ route('actions.index', $currentUser) }}" class="link-btn section-link">Manage</a>
            <x-carousel-controls label="actions" />
        </div>

        <div class="actions carousel-track" data-carousel-track tabindex="-1">
            @foreach ($homeActions as $homeAction)
                @php
                    $runUrl = $homeAction->isShared()
                        ? route($homeAction->inputs() ? 'actions.shared.run.form' : 'actions.shared.run', str($homeAction->key)->after(':')->toString())
                        : route($homeAction->inputs() ? 'actions.custom.run.form' : 'actions.custom.run', [$currentUser, str($homeAction->key)->after(':')->toString()]);
                @endphp
                <form method="{{ $homeAction->inputs() ? 'GET' : 'POST' }}" action="{{ $runUrl }}" class="action @unless ($homeAction->isShared()) action-custom @endunless"
                      data-search-item="{{ $homeAction->name }} {{ $homeAction->description }}">
                    @unless ($homeAction->inputs())
                        @csrf
                    @endunless
                    <a href="{{ $homeAction->isShared() ? route('actions.shared.show', str($homeAction->key)->after(':')->toString()) : route('actions.edit', [$currentUser, str($homeAction->key)->after(':')->toString()]) }}"
                       class="action-label" title="{{ $homeAction->description ? $homeAction->description.' · ' : '' }}Open the YAML">
                        <x-icon name="{{ $homeAction->isShared() ? 'list-checks' : 'workflow' }}" :size="20" />
                        <span>{{ $homeAction->name }}</span>
                    </a>
                    <button type="submit" class="action-run" title="Run “{{ $homeAction->name }}”">
                        <x-icon name="play" :size="24" />
                        <span class="sr-only">Run {{ $homeAction->name }}</span>
                    </button>
                </form>
            @endforeach

            <a href="{{ route('actions.create', $currentUser) }}" class="action action-new">
                <x-icon name="plus" :size="18" /> New action
            </a>
        </div>
        <p class="empty" data-search-empty hidden>No actions match your search.</p>
    </section>

    {{-- Mentions --}}
    <section class="section" id="mentions" aria-labelledby="mentions-title" data-search-section>
        <h2 class="section-title" id="mentions-title">
            Mentions <x-icon name="at" :size="24" class="title-icon" />
        </h2>

        <div class="mentions">
            @forelse ($mentions as $mention)
                <article class="mention" data-search-item="{{ $mention['author'] }} {{ $mention['ticket'] }} {{ $mention['message'] }}">
                    <div class="mention-body">
                        <div class="mention-meta">
                            <span class="mention-author">{{ $mention['author'] }}</span>
                            <time datetime="{{ $mention['received_at']->toIso8601String() }}">{{ $mention['received_at']->diffForHumans() }}</time>
                        </div>
                        <p class="mention-text">
                            <a href="{{ route('tickets.show', $mention['ticket']) }}" class="ticket">#{{ $mention['ticket'] }}</a>
                            <span class="mention-message" data-message>{{ $mention['message'] }}</span>
                        </p>
                    </div>

                    <div class="mention-actions">
                        <form method="POST" action="{{ route('mentions.resolve', $mention['ticket']) }}">
                            @csrf
                            <button type="submit" title="Mark as done"><x-icon name="check" /><span class="sr-only">Mark #{{ $mention['ticket'] }} as done</span></button>
                        </form>
                        <form method="POST" action="{{ route('mentions.dismiss', $mention['ticket']) }}">
                            @csrf
                            <button type="submit" title="Dismiss"><x-icon name="x" /><span class="sr-only">Dismiss #{{ $mention['ticket'] }}</span></button>
                        </form>
                        <details class="menu">
                            <summary title="More options"><x-icon name="more-vertical" /><span class="sr-only">More options</span></summary>
                            <div class="menu-list" role="menu">
                                <button type="button" role="menuitem" data-expand-message><x-icon name="expand" :size="16" /> Show full message</button>
                                <button type="button" role="menuitem" data-copy="#{{ $mention['ticket'] }}"><x-icon name="copy" :size="16" /> Copy ticket number</button>
                            </div>
                        </details>
                    </div>
                </article>
            @empty
                <div class="mentions-empty">
                    <p>You’re all caught up.</p>
                    @if ($handledMentions)
                        <form method="POST" action="{{ route('mentions.restore') }}">
                            @csrf
                            <button type="submit" class="link-btn">Restore {{ $handledMentions }} {{ Str::plural('mention', $handledMentions) }}</button>
                        </form>
                    @endif
                </div>
            @endforelse
        </div>
        <p class="empty" data-search-empty hidden>No mentions match your search.</p>
    </section>

    {{-- Add favorite dialog --}}
    @if (count($otherModules))
        <dialog class="dialog" id="add-favorite" aria-labelledby="add-favorite-title">
            <div class="dialog-head">
                <h2 id="add-favorite-title">Add to favorites</h2>
                <button type="button" class="tool-btn" data-dialog-close title="Close"><x-icon name="x" :size="18" /><span class="sr-only">Close</span></button>
            </div>
            <ul class="module-list">
                @foreach ($otherModules as $slug => $module)
                    <li>
                        <x-icon :name="$module['icon']" :size="24" />
                        <span>
                            <strong>{{ $module['name'] }}</strong>
                            <small>{{ $module['description'] }}</small>
                        </span>
                        <form method="POST" action="{{ route('favorites.toggle', $slug) }}">
                            @csrf
                            <button type="submit" class="btn">
                                <x-icon name="star" :size="16" /> Add
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </dialog>
    @endif
@endsection
