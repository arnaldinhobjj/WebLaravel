<form method="GET" action="{{ route('tickets.index') }}" class="search" role="search">
    <x-icon name="search" :size="16" />
    <label class="sr-only" for="ticket-search">Search tickets</label>
    <input id="ticket-search" type="search" name="q" value="{{ request('q') }}" placeholder="Search tickets" autocomplete="off" data-search-input data-search-server>
    @foreach (request()->only(['status', 'priority', 'category', 'assignee', 'requester', 'sort']) as $key => $value)
        @if (is_string($value) && $value !== '')
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endif
    @endforeach
    <kbd>Ctrl K</kbd>
</form>
