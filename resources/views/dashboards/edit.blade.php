@extends('layouts.app')

@section('title', 'Customize '.$dashboard->name)

@push('scripts')
    <script src="{{ asset('js/dashboard-customize.js') }}?v={{ filemtime(public_path('js/dashboard-customize.js')) }}" defer></script>
@endpush

@section('content')
    <a href="{{ route('dashboards.show', $dashboard) }}" class="back-link"><x-icon name="arrow-left" :size="16" /> {{ $dashboard->name }}</a>

    <div class="page-head customize-head">
        <div>
            <h2 class="section-title">Customize “{{ $dashboard->name }}”</h2>
            <p class="page-sub" id="customize-help">
                Drag widgets by <x-icon name="grip" :size="14" class="inline-icon" /> to move them, or focus that handle and use the arrow keys.
                Drag from <em>Add widgets</em> to drop one in place.
                @if ($dashboard->isShared())
                    Shared with everyone: changes here change it for everyone.
                @endif
            </p>
        </div>
        <div class="page-actions">
            <a href="{{ route('dashboards.show', $dashboard) }}" class="btn btn-primary">Done</a>
        </div>
    </div>

    <p class="sr-only" aria-live="polite" data-customize-live></p>
    <p class="customize-status" role="status" data-customize-status hidden></p>

    <div class="customize-layout">
        <section class="customize-canvas" aria-label="Widgets on this dashboard">
            <div class="dashboard-grid customize-grid" data-dashboard-canvas data-reorder-url="{{ route('dashboards.widgets.reorder', $dashboard) }}">
                @foreach ($dashboard->widgets as $widget)
                    <section @class(['card', 'dashboard-widget', 'customize-widget', 'is-wide' => $widget->isWide()])
                             data-widget-id="{{ $widget->id }}" data-widget-title="{{ $widget->displayTitle() }}"
                             aria-labelledby="widget-{{ $widget->id }}">
                        <header class="customize-widget-bar">
                            <button type="button" class="drag-handle" data-drag-handle aria-describedby="customize-help" title="Drag to move">
                                <x-icon name="grip" :size="18" />
                                <span class="sr-only">Move “{{ $widget->displayTitle() }}”</span>
                            </button>
                            <h3 id="widget-{{ $widget->id }}" class="sub-title">{{ $widget->displayTitle() }}</h3>
                            @if ($widget->type->isAboutTickets())
                                <span class="pill">{{ $widget->scope->label() }}</span>
                            @else
                                <span class="pill">Table</span>
                            @endif
                            <div class="customize-widget-actions">
                                <form method="POST" action="{{ route('dashboards.widgets.update', [$dashboard, $widget]) }}">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="size" value="{{ $widget->isWide() ? 'normal' : 'wide' }}">
                                    <button type="submit" class="tool-btn" title="{{ $widget->isWide() ? 'Make half width' : 'Make full width' }}">
                                        <x-icon :name="$widget->isWide() ? 'narrow' : 'widen'" :size="16" />
                                        <span class="sr-only">{{ $widget->isWide() ? 'Make half width' : 'Make full width' }}</span>
                                    </button>
                                </form>
                                <a href="{{ route('dashboards.edit', [$dashboard, 'widget' => $widget->id]) }}" class="tool-btn" data-settings-open="widget-settings-{{ $widget->id }}" title="Settings">
                                    <x-icon name="settings" :size="16" />
                                    <span class="sr-only">Settings for “{{ $widget->displayTitle() }}”</span>
                                </a>
                                <form method="POST" action="{{ route('dashboards.widgets.destroy', [$dashboard, $widget]) }}" data-confirm="Remove “{{ $widget->displayTitle() }}”?">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="tool-btn tool-btn-danger" title="Remove">
                                        <x-icon name="trash" :size="16" />
                                        <span class="sr-only">Remove “{{ $widget->displayTitle() }}”</span>
                                    </button>
                                </form>
                            </div>
                        </header>

                        <div class="customize-preview" inert>
                            @include('dashboards._widget-body', ['data' => $data[$widget->id]])
                        </div>
                    </section>
                @endforeach

                <p class="customize-empty" data-canvas-empty @if ($dashboard->widgets->isNotEmpty()) hidden @endif>
                    No widgets yet. Drag one here from <em>Add widgets</em>, or press Add.
                </p>
            </div>
        </section>

        <aside class="card customize-library" aria-labelledby="library-title">
            <h3 id="library-title" class="sub-title">Add widgets</h3>
            @if ($library === [])
                <p class="muted">There’s nothing you have access to add. Ask someone who manages tags for access to tickets, people, files or actions.</p>
            @else
                <ul class="library-list">
                    @foreach ($library as $item)
                        <li class="library-item" data-library-item data-wide="{{ \App\Enums\DashboardWidgetType::from($item['type'])->isWide() ? '1' : '0' }}">
                            <span class="drag-handle" data-drag-handle aria-hidden="true"><x-icon name="grip" :size="18" /></span>
                            <x-icon :name="$item['icon']" :size="20" class="library-icon" />
                            <span class="library-text">
                                <strong>{{ $item['label'] }}</strong>
                                <small>{{ $item['description'] }}</small>
                            </span>
                            <form method="POST" action="{{ route('dashboards.widgets.store', $dashboard) }}" data-library-form>
                                @csrf
                                <input type="hidden" name="type" value="{{ $item['type'] }}">
                                @if ($item['source'])
                                    <input type="hidden" name="source" value="{{ $item['source'] }}">
                                @endif
                                <input type="hidden" name="position" value="" data-position-input>
                                <button type="submit" class="btn">
                                    <x-icon name="plus" :size="14" /> Add
                                    <span class="sr-only">{{ $item['label'] }}</span>
                                </button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
            @error('type') <p class="field-error">{{ $message }}</p> @enderror
            @error('source') <p class="field-error">{{ $message }}</p> @enderror
        </aside>
    </div>

    @foreach ($dashboard->widgets as $widget)
        @include('dashboards._widget-settings')
    @endforeach

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
