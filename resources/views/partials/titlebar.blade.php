<header class="titlebar">
    <div class="titlebar-title">
        <span class="app-badge"><x-icon name="logo" :size="15" /></span>
        <h1>Fort Vale</h1>
    </div>

    <div class="titlebar-tools">
        <a class="tool-btn" href="{{ route('dashboard') }}#mentions" title="Mentions">
            <x-icon name="bell" />
            @if ($openMentionCount)
                <span class="badge">{{ $openMentionCount }}</span>
            @endif
            <span class="sr-only">{{ $openMentionCount }} unread mentions</span>
        </a>

        {{-- Profile --}}
        <details class="menu dropdown">
            <summary class="tool-btn avatar" title="Account">
                <span class="avatar-initials">{{ $currentUser->initials() }}</span>
                <span class="sr-only">Account: {{ $currentUser->name }}</span>
            </summary>
            <div class="menu-list menu-wide" role="menu">
                <div class="menu-profile">
                    <span class="avatar-initials avatar-lg">{{ $currentUser->initials() }}</span>
                    <span>
                        <strong>{{ $currentUser->name }}</strong>
                        <small>{{ $currentUser->job_title ?? $currentUser->email }}</small>
                    </span>
                </div>

                <hr>
                <a href="{{ route('tickets.index', ['assignee' => 'me']) }}" role="menuitem" class="menu-item"><x-icon name="ticket" :size="16" /> Assigned to me</a>
                <a href="{{ route('tickets.index', ['requester' => 'me', 'status' => 'all']) }}" role="menuitem" class="menu-item"><x-icon name="inbox" :size="16" /> Tickets I opened</a>
                <a href="{{ route('tickets.create') }}" role="menuitem" class="menu-item"><x-icon name="plus" :size="16" /> New ticket</a>

                <hr>
                <a href="{{ route('actions.index', $currentUser) }}" role="menuitem" class="menu-item"><x-icon name="list-checks" :size="16" /> My actions</a>
                <a href="{{ route('settings.edit', $currentUser) }}" role="menuitem" class="menu-item"><x-icon name="settings" :size="16" /> Settings</a>
                <button type="button" role="menuitem" class="menu-item" data-sidebar-toggle>
                    <x-icon name="panel-left" :size="16" /> Show or hide sidebar <kbd>[</kbd>
                </button>

                @if ($people->count() > 1)
                    <hr>
                    <p class="menu-heading">Switch user</p>
                    @foreach ($people as $person)
                        @continue($person->is($currentUser))
                        <form method="POST" action="{{ route('profile.switch', $person) }}">
                            @csrf
                            <button type="submit" role="menuitem" class="menu-item">
                                <span class="avatar-initials avatar-sm">{{ $person->initials() }}</span>
                                <span class="menu-item-text">{{ $person->name }} <small>{{ $person->job_title }}</small></span>
                            </button>
                        </form>
                    @endforeach
                @endif
            </div>
        </details>
    </div>
</header>

<div class="toolbar">
    <button type="button" class="tool-btn" data-sidebar-toggle aria-controls="sidebar" aria-expanded="true" title="Hide sidebar ( [ )">
        <x-icon name="panel-left" :size="18" />
        <span class="sr-only">Show or hide sidebar</span>
    </button>

    @hasSection('search')
        @yield('search')
    @else
        <label class="search">
            <x-icon name="search" :size="16" />
            <span class="sr-only">Search</span>
            <input type="search" placeholder="Search" autocomplete="off" data-search-input>
            <kbd>Ctrl K</kbd>
        </label>
    @endif
</div>
