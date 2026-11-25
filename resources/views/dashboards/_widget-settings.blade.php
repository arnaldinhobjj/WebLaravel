{{--
    One widget's settings, in a dialog on the customize screen. ?widget=ID
    renders it open (also without JavaScript); after a failed save it opens
    again with what was typed.
--}}
@php
    $isOld = (int) old('widget_id') === $widget->id;
    $current = $widget->tableSource();
    $settings = (array) $widget->settings;
    $chosenKey = $isOld ? old('source', $current?->key()) : $current?->key();
    if (! isset($tables[$chosenKey ?? ''])) {
        $chosenKey = array_key_first($tables);
    }
    $isOpen = $openWidget === $widget->id;
@endphp
<dialog class="dialog widget-settings" id="widget-settings-{{ $widget->id }}" aria-labelledby="widget-settings-{{ $widget->id }}-title"
        @if ($isOpen) open data-open-on-load @endif>
    <form method="POST" action="{{ route('dashboards.widgets.update', [$dashboard, $widget]) }}">
        @csrf @method('PATCH')
        <input type="hidden" name="widget_id" value="{{ $widget->id }}">

        <div class="dialog-head">
            <h2 id="widget-settings-{{ $widget->id }}-title">{{ $widget->displayTitle() }}</h2>
            <a href="{{ route('dashboards.edit', $dashboard) }}" class="tool-btn" data-dialog-close title="Close">
                <x-icon name="x" :size="18" /><span class="sr-only">Close</span>
            </a>
        </div>

        <div class="dialog-body">
            @if ($isOpen && $errors->any())
                <p class="field-error" role="alert">Some settings need another look.</p>
            @endif

            <label class="field">
                <span>Title</span>
                <input type="text" name="title" maxlength="60" value="{{ $isOld ? old('title') : $widget->title }}"
                       placeholder="{{ $current?->label() ?? $widget->type->label() }}">
                @if ($isOld) @error('title') <p class="field-error">{{ $message }}</p> @enderror @endif
            </label>

            <fieldset class="field check-list">
                <legend>Width</legend>
                @foreach (\App\Enums\WidgetSize::cases() as $size)
                    <label class="check">
                        <input type="radio" name="size" value="{{ $size->value }}" @checked(($isOld ? old('size') : ($widget->isWide() ? 'wide' : 'normal')) === $size->value)>
                        {{ $size->label() }}
                    </label>
                @endforeach
            </fieldset>

            @if ($widget->type->isAboutTickets())
                <label class="field">
                    <span>Tickets</span>
                    <select name="scope">
                        @foreach (\App\Enums\WidgetScope::cases() as $scope)
                            <option value="{{ $scope->value }}" @selected(($isOld ? old('scope') : $widget->scope->value) === $scope->value)>{{ $scope->label() }}</option>
                        @endforeach
                    </select>
                    <small class="muted">“Just mine” shows each viewer the tickets assigned to them.</small>
                </label>
            @elseif ($tables === [])
                <p class="notice">You don’t have access to any tables, so you can only change the title and width.</p>
            @else
                @if ($current && ! isset($tables[$current->key()]))
                    <p class="notice">This widget shows {{ $current->label() }}, which you don’t have access to. Pick another table to change it.</p>
                @endif

                <label class="field">
                    <span>Table</span>
                    <select name="source" data-table-select>
                        @foreach ($tables as $key => $table)
                            <option value="{{ $key }}" @selected($chosenKey === $key)>{{ $table->label() }}</option>
                        @endforeach
                    </select>
                    @if ($isOld) @error('source') <p class="field-error">{{ $message }}</p> @enderror @endif
                </label>

                @foreach ($tables as $key => $table)
                    @php
                        $isSaved = $current?->key() === $key;
                        $available = $table->columns($me);
                        $selected = $isOld && $chosenKey === $key
                            ? (array) old('columns', [])
                            : ($isSaved ? (array) ($settings['columns'] ?? []) : $table->defaultColumns());
                        $selected = array_values(array_intersect($selected, array_keys($available)));
                        $ordered = array_merge($selected, array_values(array_diff(array_keys($available), $selected)));
                    @endphp
                    <fieldset class="table-options" data-table-options="{{ $key }}" @if ($key !== $chosenKey) hidden disabled @endif>
                        <legend class="sr-only">{{ $table->label() }} options</legend>

                        <div class="field">
                            <span id="columns-{{ $widget->id }}-{{ $key }}">Columns <small class="muted">drag to reorder</small></span>
                            <ul class="column-list" data-sortable-list aria-labelledby="columns-{{ $widget->id }}-{{ $key }}">
                                @foreach ($ordered as $column)
                                    <li class="column-item" data-sortable-item>
                                        <button type="button" class="drag-handle" data-drag-handle title="Drag to move">
                                            <x-icon name="grip" :size="16" /><span class="sr-only">Move {{ $available[$column] }}</span>
                                        </button>
                                        <label class="check">
                                            <input type="checkbox" name="columns[]" value="{{ $column }}" @checked(in_array($column, $selected, true))>
                                            {{ $available[$column] }}
                                        </label>
                                    </li>
                                @endforeach
                            </ul>
                            @if ($isOld && $chosenKey === $key)
                                @error('columns') <p class="field-error">{{ $message }}</p> @enderror
                                @error('columns.*') <p class="field-error">{{ $message }}</p> @enderror
                            @endif
                        </div>

                        <div class="field-row field-row-2">
                            @foreach ($table->filters($me) as $filterKey => $filter)
                                @php
                                    $value = $isOld && $chosenKey === $key
                                        ? (string) old("filters.{$filterKey}")
                                        : ($isSaved ? (string) ($settings['filters'][$filterKey] ?? $filter['default']) : $filter['default']);
                                @endphp
                                <label class="field">
                                    <span>{{ $filter['label'] }}</span>
                                    <select name="filters[{{ $filterKey }}]">
                                        @foreach ($filter['options'] as $optionValue => $optionLabel)
                                            <option value="{{ $optionValue }}" @selected($value === (string) $optionValue)>{{ $optionLabel }}</option>
                                        @endforeach
                                    </select>
                                    @if ($isOld && $chosenKey === $key) @error("filters.{$filterKey}") <p class="field-error">{{ $message }}</p> @enderror @endif
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach

                <label class="field">
                    <span>Rows</span>
                    <select name="limit">
                        @foreach (\App\Services\DashboardTables\TableSource::LIMITS as $limit)
                            <option value="{{ $limit }}" @selected((int) ($isOld ? old('limit') : ($settings['limit'] ?? \App\Services\DashboardTables\TableSource::DEFAULT_LIMIT)) === $limit)>Up to {{ $limit }}</option>
                        @endforeach
                    </select>
                </label>
                <p class="muted small">Filters only offer what you have access to. Everyone looking at this dashboard sees only the rows they can open themselves.</p>
            @endif
        </div>

        <div class="dialog-actions">
            <a href="{{ route('dashboards.edit', $dashboard) }}" class="btn" data-dialog-close>Cancel</a>
            <button type="submit" class="btn btn-primary">Save</button>
        </div>
    </form>
</dialog>
