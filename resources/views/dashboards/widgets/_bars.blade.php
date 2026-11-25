{{-- One-series horizontal bars; each row is also a readable label + number. --}}
@if ($data['total'] === 0)
    <p class="muted">No tickets here right now.</p>
@else
    <ul class="dashboard-bars">
        @foreach ($data['bars'] as $bar)
            <li>
                <a href="{{ $bar['url'] }}" class="dashboard-bar" title="{{ $bar['label'] }}: {{ $bar['value'] }}">
                    <span class="dashboard-bar-label">{{ $bar['label'] }}</span>
                    <span class="dashboard-bar-track"><span class="dashboard-bar-fill" style="width: {{ $data['max'] > 0 ? round($bar['value'] / $data['max'] * 100, 1) : 0 }}%"></span></span>
                    <span class="dashboard-bar-value">{{ number_format($bar['value']) }}</span>
                </a>
            </li>
        @endforeach
    </ul>
@endif
