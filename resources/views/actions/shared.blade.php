@extends('layouts.app')

@section('title', $workflow->name)

@section('content')
    <a href="{{ route('actions.index', $currentUser) }}" class="back-link"><x-icon name="arrow-left" :size="16" /> Actions</a>

    <div class="page-head">
        <div>
            <h2 class="section-title">{{ $workflow->name }}</h2>
            <p class="page-sub">Shared action <code>actions/{{ $slug }}.yml</code>. To change it, edit the file in the repository.</p>
        </div>
        @if ($workflow->canRunManually())
            @include('actions.partials.run-button', ['workflow' => $workflow, 'url' => $workflow->inputs() ? route('actions.shared.run.form', $slug) : route('actions.shared.run', $slug)])
        @endif
    </div>

    <pre class="card yaml-view"><code>{{ $workflow->yaml }}</code></pre>
@endsection
