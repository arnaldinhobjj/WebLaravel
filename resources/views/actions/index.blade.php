@extends('layouts.app')

@section('title', 'Actions')

@php
    $triggerLabels = ['run' => 'Run button', 'ticket' => 'Ticket events', 'schedule' => 'Schedule'];
@endphp

@section('content')
    <div class="page-head">
        <div>
            <h2 class="section-title">Actions <x-icon name="workflow" :size="28" class="title-icon" /></h2>
            <p class="page-sub">Actions are YAML files, like GitHub Actions: a name, what starts them (<code>on:</code>) and the <code>steps</code> they run.</p>
        </div>
        <a href="{{ route('actions.create', $user) }}" class="btn btn-primary"><x-icon name="plus" :size="16" /> New action</a>
    </div>

    {{-- Shared actions --}}
    <section aria-labelledby="shared-title">
        <h3 id="shared-title" class="sub-title">Shared actions</h3>
        <p class="muted section-note">From the <code>actions/</code> folder in the project. Everyone can run them; edit them in the repository.</p>

        @if ($shared === [])
            <p class="muted">No shared actions yet. Add a <code>.yml</code> file to <code>actions/</code>.</p>
        @else
            <ul class="action-cards">
                @foreach ($shared as $slug => $workflow)
                    <li class="card action-card">
                        @if ($workflow instanceof \App\Services\Workflows\Workflow)
                            <div class="action-card-head">
                                <span class="action-card-icon"><x-icon name="list-checks" :size="20" /></span>
                                <div class="action-card-title">
                                    <h3>{{ $workflow->name }}</h3>
                                    <p class="muted"><code>actions/{{ $slug }}.yml</code></p>
                                </div>
                            </div>
                            @if ($workflow->description)
                                <p class="action-card-description">{{ $workflow->description }}</p>
                            @endif
                            <p class="trigger-chips">
                                @foreach ($workflow->triggers() as $trigger)
                                    <span class="pill">{{ $triggerLabels[$trigger] }}</span>
                                @endforeach
                                <span class="muted small">{{ count($workflow->steps) }} {{ Str::plural('step', count($workflow->steps)) }}</span>
                            </p>
                            <div class="action-card-foot">
                                <a href="{{ route('actions.shared.show', $slug) }}" class="link-btn small">View YAML</a>
                                @if ($workflow->canRunManually())
                                    @include('actions.partials.run-button', ['workflow' => $workflow, 'url' => $workflow->inputs() ? route('actions.shared.run.form', $slug) : route('actions.shared.run', $slug)])
                                @endif
                            </div>
                        @else
                            <div class="action-card-head">
                                <span class="action-card-icon action-card-icon-error"><x-icon name="x" :size="20" /></span>
                                <div class="action-card-title">
                                    <h3><code>actions/{{ $slug }}.yml</code></h3>
                                    <p class="muted">This file has mistakes and can't run:</p>
                                </div>
                            </div>
                            <ul class="yaml-errors">
                                @foreach ($workflow->errors as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- My actions --}}
    <section aria-labelledby="mine-title" class="actions-section">
        <h3 id="mine-title" class="sub-title">My actions</h3>

        @if ($actions->isEmpty())
            <div class="empty-state action-empty">
                <x-icon name="workflow" :size="40" />
                <p><strong>You haven’t made any actions yet.</strong></p>
                <p>Start from a template: open a page, start a ticket, react to ticket events, call a webhook, or run on a schedule.</p>
                <a href="{{ route('actions.create', $user) }}" class="btn btn-primary"><x-icon name="plus" :size="16" /> Create your first action</a>
            </div>
        @else
            <ul class="action-cards">
                @foreach ($actions as $action)
                    @php $workflow = $action->workflow(); @endphp
                    <li class="card action-card">
                        <div class="action-card-head">
                            <span class="action-card-icon"><x-icon name="workflow" :size="20" /></span>
                            <div class="action-card-title">
                                <h3>{{ $workflow->name }}</h3>
                                @if ($workflow->description)
                                    <p class="muted">{{ $workflow->description }}</p>
                                @endif
                            </div>
                            @if ($workflow->showOnHome())
                                <span class="pill status-open">On home</span>
                            @endif
                        </div>
                        <p class="trigger-chips">
                            @foreach ($workflow->triggers() as $trigger)
                                <span class="pill">{{ $triggerLabels[$trigger] }}</span>
                            @endforeach
                            <span class="muted small">{{ count($workflow->steps) }} {{ Str::plural('step', count($workflow->steps)) }}</span>
                        </p>
                        <div class="action-card-foot">
                            <span class="muted small">{{ $action->last_run_at ? 'Last run '.$action->last_run_at->diffForHumans() : 'Never run' }}</span>
                            <div class="action-card-buttons">
                                <form method="POST" action="{{ route('actions.destroy', [$user, $action]) }}" data-confirm="Delete “{{ $action->name }}”?">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-ghost-danger">Delete</button>
                                </form>
                                <a href="{{ route('actions.edit', [$user, $action]) }}" class="btn">Edit</a>
                                @if ($workflow->canRunManually())
                                    @include('actions.partials.run-button', ['workflow' => $workflow, 'url' => $workflow->inputs() ? route('actions.custom.run.form', [$user, $action]) : route('actions.custom.run', [$user, $action])])
                                @endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Recent runs --}}
    <section aria-labelledby="runs-title" class="actions-section">
        <h3 id="runs-title" class="sub-title">Recent runs</h3>
        <p class="muted section-note">Runs you started and runs of your own actions, newest first.</p>

        @if ($runs->isEmpty())
            <p class="muted">No runs yet.</p>
        @else
            <ul class="run-list">
                @foreach ($runs as $run)
                    <li id="run-{{ $run->id }}">
                        <details class="card run">
                            <summary class="run-summary">
                                <span @class(['pill', 'status-resolved' => $run->succeeded(), 'priority-urgent' => ! $run->succeeded()])>{{ $run->succeeded() ? 'Succeeded' : 'Failed' }}</span>
                                <strong>{{ $run->action_name }}</strong>
                                <span class="muted small">
                                    {{ $triggerLabels[$run->trigger] ?? $run->trigger }}@if ($run->event) ({{ str_replace('_', ' ', $run->event) }})@endif
                                    @if ($run->ticket_id) · <a href="{{ route('tickets.show', $run->ticket_id) }}">#{{ $run->ticket_id }}</a>@endif
                                    · {{ $run->user?->name ?? 'Scheduler' }}
                                    · <time datetime="{{ $run->started_at->toIso8601String() }}">{{ $run->started_at->diffForHumans() }}</time>
                                </span>
                            </summary>
                            <ol class="run-steps">
                                @foreach ($run->steps as $step)
                                    <li class="run-step run-step-{{ $step['status'] }}">
                                        <span class="run-step-status">{{ ['success' => '✓', 'failed' => '✕', 'skipped' => '–'][$step['status']] ?? '?' }}</span>
                                        <span>
                                            <strong>{{ $step['name'] }}</strong> <code>{{ $step['uses'] }}</code>
                                            @if (isset($step['ms']))<span class="muted small">{{ $step['ms'] }} ms</span>@endif
                                            @if (is_string($step['output']) && $step['output'] !== '')
                                                <span class="run-step-output">{{ $step['output'] }}</span>
                                            @elseif (is_array($step['output']) && $step['output'] !== [])
                                                <code class="run-step-output">{{ json_encode($step['output'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</code>
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ol>
                        </details>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endsection
