<p class="muted small">{{ number_format($data['total']) }} resolved or closed in the last {{ count($data['days']) }} days.</p>
<ol class="dashboard-columns" aria-label="Tickets resolved per day">
    @foreach ($data['days'] as $day)
        <li class="dashboard-column" title="{{ $day['date']->format('D j M') }}: {{ $day['value'] }}">
            <span class="dashboard-column-value">{{ $day['value'] ?: '' }}</span>
            <span class="dashboard-column-track"><span class="dashboard-column-fill" style="height: {{ $data['max'] > 0 ? round($day['value'] / $data['max'] * 100, 1) : 0 }}%"></span></span>
            <span class="dashboard-column-label">{{ $day['date']->format('j') }}</span>
            <span class="sr-only">{{ $day['date']->format('j F') }}: {{ $day['value'] }}</span>
        </li>
    @endforeach
</ol>
