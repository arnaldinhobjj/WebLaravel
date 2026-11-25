@extends('layouts.app')

@section('title', 'Customize '.$dashboard->name)

@section('content')
    <a href="{{ route('dashboards.show', $dashboard) }}" class="back-link"><x-icon name="arrow-left" :size="16" /> {{ $dashboard->name }}</a>

    <div class="page-head">
        <div>
            <h2 class="section-title">Customize dashboard</h2>
            <p class="page-sub">
                @if ($dashboard->isShared())
                    Shared with everyone: changes here change it for everyone.
                @else
                    Only you can see this dashboard.
                @endif
            </p>
        </div>
    </div>

    <section class="card ticket-form" aria-labelledby="widgets-title">
        <h3 id="widgets-title" class="sub-title">Widgets</h3>

        @forelse ($dashboard->widgets as $widget)
            <div class="dashboard-widget-row">
                <div>
                    <strong>{{ $widget->type->label() }}</strong>
                    <span class="pill">{{ $widget->scope->label() }}</span>
                    <p class="muted small">{{ $widget->type->description() }}</p>
                </div>
                <div class="dashboard-widget-row-actions">
                    @foreach (['up' => 'Move up', 'down' => 'Move down'] as $direction => $label)
                        <form method="POST" action="{{ route('dashboards.widgets.update', [$dashboard, $widget]) }}">
                            @csrf @method('PATCH')
                            <input type="hidden" name="direction" value="{{ $direction }}">
                            <button type="submit" class="btn" @disabled(($direction === 'up' && $loop->parent->first) || ($direction === 'down' && $loop->parent->last))>{{ $label }}</button>
                        </form>
                    @endforeach
                    <form method="POST" action="{{ route('dashboards.widgets.destroy', [$dashboard, $widget]) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-ghost-danger">Remove</button>
                    </form>
                </div>
            </div>
        @empty
            <p class="muted">No widgets yet. Add the first one below.</p>
        @endforelse

        <form method="POST" action="{{ route('dashboards.widgets.store', $dashboard) }}" class="dashboard-add-widget">
            @csrf
            <label class="field">
                <span>Widget</span>
                @php($selectedType = \App\Enums\DashboardWidgetType::tryFrom((string) old('type')) ?? $types[0])
                <select name="type" data-describe="widget-type-description" aria-describedby="widget-type-description">
                    @foreach ($types as $type)
                        <option value="{{ $type->value }}" data-description="{{ $type->description() }}" @selected($selectedType === $type)>{{ $type->label() }}</option>
                    @endforeach
                </select>
                <small class="muted" id="widget-type-description">{{ $selectedType->description() }}</small>
                @error('type') <p class="field-error">{{ $message }}</p> @enderror
            </label>
            <label class="field">
                <span>Tickets</span>
                <select name="scope">
                    @foreach ($scopes as $scope)
                        <option value="{{ $scope->value }}" @selected(old('scope', $dashboard->isShared() ? 'everyone' : 'mine') === $scope->value)>{{ $scope->label() }}</option>
                    @endforeach
                </select>
                @error('scope') <p class="field-error">{{ $message }}</p> @enderror
            </label>
            <button type="submit" class="btn btn-primary"><x-icon name="plus" :size="16" /> Add widget</button>
        </form>
    </section>

    <form method="POST" action="{{ route('dashboards.update', $dashboard) }}" class="card ticket-form">
        @csrf @method('PUT')
        <h3 class="sub-title">Details</h3>
        @include('dashboards._fields')
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save</button>
        </div>
    </form>

    @can('delete', $dashboard)
        <form method="POST" action="{{ route('dashboards.destroy', $dashboard) }}" class="card ticket-form dashboard-delete" data-confirm="Delete “{{ $dashboard->name }}”?{{ $dashboard->isShared() ? ' It disappears for everyone.' : '' }}">
            @csrf @method('DELETE')
            <h3 class="sub-title">Delete dashboard</h3>
            <div class="form-actions">
                <button type="submit" class="btn btn-ghost-danger">Delete dashboard</button>
            </div>
        </form>
    @endcan
@endsection
