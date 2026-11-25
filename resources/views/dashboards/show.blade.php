@extends('layouts.app')

@section('title', $dashboard->name)

@section('content')
    <div class="page-head">
        <div>
            <h2 class="section-title">{{ $dashboard->name }}</h2>
            <p class="page-sub">
                @if ($dashboard->isShared())
                    Shared with everyone. “Just mine” widgets show each person their own tickets.
                @else
                    Only you can see this dashboard.
                @endif
                @if ($isDefault)
                    · Opens at /dashboard
                @endif
            </p>
        </div>
        <div class="page-actions">
            @unless ($isDefault)
                <form method="POST" action="{{ route('dashboards.default', $dashboard) }}">
                    @csrf
                    <button type="submit" class="btn">Open this at /dashboard</button>
                </form>
            @endunless
            @can('copy', $dashboard)
                @if ($dashboard->isShared())
                    <form method="POST" action="{{ route('dashboards.copy', $dashboard) }}">
                        @csrf
                        <button type="submit" class="btn">Make my own copy</button>
                    </form>
                @endif
            @endcan
            @can('update', $dashboard)
                <a href="{{ route('dashboards.edit', $dashboard) }}" class="btn btn-primary">Customize</a>
            @endcan
        </div>
    </div>

    @include('dashboards._switcher')

    @if ($dashboard->widgets->isEmpty())
        <div class="empty-state">
            <p><strong>This dashboard has no widgets yet.</strong></p>
            @can('update', $dashboard)
                <p><a href="{{ route('dashboards.edit', $dashboard) }}" class="link-btn">Add widgets</a></p>
            @endcan
        </div>
    @else
        <div class="dashboard-grid">
            @foreach ($dashboard->widgets as $widget)
                <section @class(['card', 'dashboard-widget', 'is-wide' => $widget->type->isWide()]) aria-labelledby="widget-{{ $widget->id }}">
                    <header class="dashboard-widget-head">
                        <h3 id="widget-{{ $widget->id }}" class="sub-title">{{ $widget->type->label() }}</h3>
                        <span class="pill">{{ $widget->scope->label() }}</span>
                    </header>

                    @if ($data[$widget->id]['denied'] ?? false)
                        <p class="muted">You don’t have access to tickets.</p>
                    @else
                        @include('dashboards.widgets.'.str_replace('_', '-', $widget->type->value), ['data' => $data[$widget->id], 'widget' => $widget])
                    @endif
                </section>
            @endforeach
        </div>
    @endif
@endsection
