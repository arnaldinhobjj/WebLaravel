<div class="dashboard-tiles">
    @foreach ($data['tiles'] as $tile)
        <a href="{{ $tile['url'] }}" @class(['dashboard-tile', 'is-urgent' => ($tile['tone'] ?? null) === 'urgent' && $tile['value'] > 0])>
            <span class="dashboard-tile-value">{{ number_format($tile['value']) }}</span>
            <span class="dashboard-tile-label">{{ $tile['label'] }}</span>
        </a>
    @endforeach
</div>
