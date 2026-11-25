@if ($data['activities']->isEmpty())
    <p class="muted">No activity yet.</p>
@else
    <ul class="dashboard-list">
        @foreach ($data['activities'] as $activity)
            <li class="dashboard-list-item">
                <span class="dashboard-list-text">
                    <strong>{{ $activity->user?->name ?? 'Someone' }}</strong>
                    {{ $activity->type === 'comment' ? 'replied on' : $activity->summary().' —' }}
                    <a href="{{ route('tickets.show', $activity->ticket_id) }}">#{{ $activity->ticket_id }} {{ $activity->ticket?->subject }}</a>
                </span>
                <time class="muted small" datetime="{{ $activity->created_at->toIso8601String() }}">{{ $activity->created_at->diffForHumans(short: true) }}</time>
            </li>
        @endforeach
    </ul>
@endif
