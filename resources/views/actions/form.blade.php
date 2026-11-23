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

    <div class="yaml-layout">
        <form method="POST" action="{{ $action->exists ? route('actions.update', [$user, $action]) : route('actions.store', $user) }}" class="card yaml-form">
            @csrf
            @if ($action->exists)
                @method('PUT')
            @endif

            @if ($errors->has('yaml'))
                <div class="yaml-errors-box" role="alert">
                    <strong>Fix {{ count($errors->get('yaml')) }} {{ Str::plural('problem', count($errors->get('yaml'))) }} before saving:</strong>
                    <ul class="yaml-errors">
                        @foreach ($errors->get('yaml') as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <label class="sr-only" for="yaml">Action YAML</label>
            <textarea id="yaml" name="yaml" class="yaml-editor" rows="24" spellcheck="false" autocapitalize="off" autocomplete="off"
                      data-yaml-editor @error('yaml') aria-invalid="true" @enderror>{{ old('yaml', $yaml) }}</textarea>

            <div class="form-actions">
                <a href="{{ route('actions.index', $user) }}" class="btn">Cancel</a>
                <button type="submit" class="btn btn-primary">{{ $action->exists ? 'Save action' : 'Create action' }}</button>
            </div>
        </form>

        <aside class="card yaml-help" aria-labelledby="yaml-help-title">
            <h3 id="yaml-help-title" class="sub-title">Quick reference</h3>
            <dl>
                <dt><code>on: run</code></dt>
                <dd>A Run button. <code>show_on_home: true</code> adds it to the home screen; <code>inputs:</code> asks for values first.</dd>
                <dt><code>on: ticket</code></dt>
                <dd><code>types: [created, status_changed, assigned, replied]</code>, optionally <code>if:</code> category, priority, status or assignee. Runs only for tickets you opened or are assigned to.</dd>
                <dt><code>on: schedule</code></dt>
                <dd><code>- cron: "0 9 * * 1"</code> (Mondays 09:00).</dd>
                <dt>Steps (<code>uses:</code>)</dt>
                <dd>
                    <code>ticket/create</code>, <code>ticket/update</code>, <code>ticket/reply</code>, <code>http/request</code>, <code>message/show</code>,
                    and as the last step of a Run-button action <code>page/open</code> or <code>ticket/open-form</code>.
                </dd>
                <dt>Values</dt>
                <dd><code>${{ '{{' }} inputs.name }}</code>, <code>${{ '{{' }} ticket.id }}</code>, <code>${{ '{{' }} actor.name }}</code>, <code>${{ '{{' }} steps.&lt;id&gt;.ticket }}</code></dd>
            </dl>
            <p class="muted small">The full reference is in the README, under “Actions (YAML)”.</p>
        </aside>
    </div>
@endsection
