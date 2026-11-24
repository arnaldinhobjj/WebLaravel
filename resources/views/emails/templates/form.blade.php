@extends('layouts.app')

@section('title', $template->exists ? $template->name : 'New template')

@section('content')
    <a href="{{ route('email-templates.index') }}" class="back-link"><x-icon name="arrow-left" :size="16" /> Email templates</a>

    <div class="page-head">
        <div>
            <h2 class="section-title">{{ $template->exists ? $template->name : 'New template' }}</h2>
            @if ($template->isBuiltIn())
                <p class="page-sub">Built in: sent automatically for ticket events. You can change the wording but not delete it.</p>
            @endif
        </div>
        @if ($template->exists)
            <form method="POST" action="{{ route('email-templates.test', $template) }}">
                @csrf
                <button type="submit" class="btn">Send a test to me</button>
            </form>
        @endif
    </div>

    <div class="yaml-layout">
        <form method="POST" action="{{ $template->exists ? route('email-templates.update', $template) : route('email-templates.store') }}" class="narrow-form">
            @csrf
            @if ($template->exists) @method('PUT') @endif
            <div class="card">
                <label class="field">
                    <span>Name</span>
                    <input type="text" name="name" value="{{ old('name', $template->name) }}" required maxlength="60" @error('name') aria-invalid="true" @enderror>
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </label>
                <label class="field">
                    <span>Subject</span>
                    <input type="text" name="subject" value="{{ old('subject', $template->subject) }}" required maxlength="200" @error('subject') aria-invalid="true" @enderror>
                    @error('subject') <p class="field-error">{{ $message }}</p> @enderror
                </label>
                <label class="field">
                    <span>Message</span>
                    <textarea name="body" rows="12" required maxlength="10000" @error('body') aria-invalid="true" @enderror>{{ old('body', $template->body) }}</textarea>
                    @error('body') <p class="field-error">{{ $message }}</p> @enderror
                </label>
            </div>
            <div class="form-actions">
                <a href="{{ route('email-templates.index') }}" class="btn">Cancel</a>
                <button type="submit" class="btn btn-primary">{{ $template->exists ? 'Save template' : 'Create template' }}</button>
            </div>
        </form>

        <aside class="card yaml-help" aria-labelledby="placeholders-title">
            <h3 id="placeholders-title" class="sub-title">Placeholders</h3>
            <dl>
                <dt><code>${{ '{{' }} recipient.name }}</code></dt><dd>The person receiving it.</dd>
                <dt><code>${{ '{{' }} actor.name }}</code></dt><dd>Who sent it or caused the change.</dd>
                <dt><code>${{ '{{' }} ticket.id }}</code>, <code>ticket.subject</code>, <code>ticket.status</code>, <code>ticket.priority</code>, <code>ticket.category</code>, <code>ticket.url</code></dt><dd>The ticket, in ticket emails and actions about a ticket.</dd>
                <dt><code>${{ '{{' }} reply.body }}</code></dt><dd>The reply, in the “new reply” email.</dd>
                <dt><code>${{ '{{' }} inputs.name }}</code></dt><dd>An action’s inputs, when an action sends it.</dd>
            </dl>
            <p class="muted small">Placeholders that don’t apply are left empty.</p>
        </aside>
    </div>

    @if ($template->exists)
        <x-attachments type="email_template" :id="$template->id" title="Files for this template" class="attachments-below" />
    @endif

    @if ($template->exists && ! $template->isBuiltIn() && auth()->user()->can('email_templates.delete'))
        <form method="POST" action="{{ route('email-templates.destroy', $template) }}" data-confirm="Delete the template “{{ $template->name }}”?" class="narrow-form">
            @csrf
            @method('DELETE')
            <div><button type="submit" class="btn btn-ghost-danger">Delete template</button></div>
        </form>
    @endif
@endsection
