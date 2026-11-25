{{-- What a widget shows; the same on the dashboard and on the customize screen. --}}
@if (isset($data['denied']))
    <p class="muted">{{ $data['denied'] }}</p>
@else
    @include('dashboards.widgets.'.str_replace('_', '-', $widget->type->value), ['data' => $data, 'widget' => $widget])
@endif
