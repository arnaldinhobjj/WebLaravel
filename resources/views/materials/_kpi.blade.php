{{-- One KPI tile: $label, $value, $unit, $spec (the limit, or a note) and $passed (true, false or null). --}}
<div @class(['kpi', 'pass' => $passed === true, 'fail' => $passed === false])>
    <span class="kpi-label">{{ $label }}</span>
    <span class="kpi-value">{{ $value }}@if ($unit && $value !== '—')<small> {{ $unit }}</small>@endif</span>
    @if ($spec)
        <span class="kpi-spec">
            @if ($passed !== null)<x-icon :name="$passed ? 'check' : 'x'" :size="12" />@endif
            {{ $spec }}
        </span>
    @endif
</div>
