@if ($data['tickets']->isEmpty())
    <p class="muted">Nothing open. Nice.</p>
@else
    <ul class="dashboard-list">
        @foreach ($data['tickets'] as $ticket)
            <li>
                <a href="{{ route('tickets.show', $ticket) }}" class="dashboard-list-item">
                    <span class="ticket-id">#{{ $ticket->id }}</span>
                    <span class="dashboard-list-text">{{ $ticket->subject }}</span>
                    <x-priority-pill :priority="$ticket->priority" />
                </a>
            </li>
        @endforeach
    </ul>
    <p><a href="{{ $data['url'] }}" class="link-btn">All of them</a></p>
@endif
