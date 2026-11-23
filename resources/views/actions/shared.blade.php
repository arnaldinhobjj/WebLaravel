@extends('layouts.app')

@section('title', $workflow?->name ?? $fileName)

@section('content')
    <a href="{{ route('actions.index', $currentUser) }}" class="back-link"><x-icon name="arrow-left" :size="16" /> Actions</a>

    <div class="page-head">
        <div>
            <h2 class="section-title">{{ $workflow?->name ?? $fileName }}</h2>
            <p class="page-sub">
                Shared action, saved in <code>{{ $fileName }}</code> in the project.
                @can('actions.shared.edit') Changes apply to everyone. @else You can read and run it; changing it needs a tag that allows editing shared actions. @endcan
            </p>
        </div>
        @if ($workflow?->canRunManually())
            @include('actions.partials.run-button', ['workflow' => $workflow, 'url' => $workflow->inputs() ? route('actions.shared.run.form', $slug) : route('actions.shared.run', $slug)])
        @endif
    </div>

    @if ($fileErrors !== [] && ! $errors->has('yaml'))
        <div class="yaml-errors-box" role="alert">
            <strong>This file has mistakes and can't run until they're fixed:</strong>
            <ul class="yaml-errors">
                @foreach ($fileErrors as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @include('actions.partials.editor', [
        'formAction' => route('actions.shared.update', $slug),
        'method' => 'PUT',
        'submitLabel' => 'Save file',
        'cancelUrl' => route('actions.index', $currentUser),
        'hidden' => ['hash' => $hash],
        'readonly' => ! auth()->user()->can('actions.shared.edit'),
    ])
@endsection
