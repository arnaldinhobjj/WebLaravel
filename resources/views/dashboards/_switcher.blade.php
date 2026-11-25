{{-- Tabs for every dashboard the person can open: shared first, then their own. --}}
<nav class="tabs dashboard-tabs" aria-label="Dashboards">
    @foreach ($shared as $item)
        <a href="{{ route('dashboards.show', $item) }}" @class(['tab', 'is-active' => $item->is($dashboard)]) @if ($item->is($dashboard)) aria-current="page" @endif>
            {{ $item->name }} <span class="tab-count">Shared</span>
        </a>
    @endforeach
    @foreach ($personal as $item)
        <a href="{{ route('dashboards.show', $item) }}" @class(['tab', 'is-active' => $item->is($dashboard)]) @if ($item->is($dashboard)) aria-current="page" @endif>
            {{ $item->name }} <span class="tab-count">Mine</span>
        </a>
    @endforeach
    @can('dashboards.create')
        <a href="{{ route('dashboards.create') }}" class="tab"><x-icon name="plus" :size="14" /> New</a>
    @endcan
</nav>
