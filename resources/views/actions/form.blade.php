@extends('layouts.app')

@section('title', $action->exists ? 'Edit action' : 'New action')

@section('content')
    <a href="{{ route('actions.index', $user) }}" class="back-link"><x-icon name="arrow-left" :size="16" /> Actions</a>

    <div class="page-head">
        <div>
            <h2 class="section-title">{{ $action->exists ? 'Edit “'.$action->name.'”' : 'New action' }}</h2>
            <p class="page-sub">Write the action in YAML. It is checked when you save; mistakes are listed with where they are.</p>
        </div>
    </div>

    @unless ($action->exists)
        <nav class="template-picker" aria-label="Start from a template">
            <span class="muted small">Start from:</span>
            @foreach (\App\Services\Workflows\ActionTemplates::TEMPLATES as $key => $template)
                <a href="{{ route('actions.create', [$user, 'template' => $key]) }}" @class(['chip', 'is-active' => request('template', 'page') === $key])>{{ $template['label'] }}</a>
            @endforeach
        </nav>
    @endunless

    @include('actions.partials.editor', [
        'formAction' => $action->exists ? route('actions.update', [$user, $action]) : route('actions.store', $user),
        'method' => $action->exists ? 'PUT' : 'POST',
        'submitLabel' => $action->exists ? 'Save action' : 'Create action',
        'cancelUrl' => route('actions.index', $user),
    ])
@endsection
