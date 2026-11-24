@extends('layouts.app')

@section('title', 'Email')

@section('content')
    <div class="page-head">
        <div>
            <h2 class="section-title">Email <x-icon name="message" :size="26" class="title-icon" /></h2>
            <p class="page-sub">Each person gets their own copy, so addresses aren’t shared. <code>${{ '{{' }} recipient.name }}</code> becomes each person’s name.</p>
        </div>
        @can('email_templates.view')
            <a href="{{ route('email-templates.index') }}" class="btn">Templates</a>
        @endcan
    </div>

    @can('emails.send')
        @if ($templates->isNotEmpty())
            <nav class="template-picker" aria-label="Start from a template">
                <span class="muted small">Start from:</span>
                @foreach ($templates as $choice)
                    <a href="{{ route('emails.create', ['template' => $choice->id]) }}" @class(['chip', 'is-active' => $template?->is($choice)])>{{ $choice->name }}</a>
                @endforeach
            </nav>
        @endif

        <form method="POST" action="{{ route('emails.store') }}" class="narrow-form compose-form">
            @csrf
            <div class="card">
                <fieldset class="check-list">
                    <legend>To</legend>
                    <div class="people-picks">
                        @foreach ($people as $person)
                            <label class="check">
                                <input type="checkbox" name="people[]" value="{{ $person->id }}" @checked(in_array($person->id, array_map('intval', old('people', [])), true))>
                                <span>{{ $person->name }}<small>{{ $person->email }}</small></span>
                            </label>
                        @endforeach
                    </div>
                    @error('people') <p class="field-error">{{ $message }}</p> @enderror
                </fieldset>
                <label class="field">
                    <span>Other addresses <small class="muted">separated by commas</small></span>
                    <input type="text" name="addresses" value="{{ old('addresses') }}" placeholder="name@example.com, other@example.com" @error('addresses') aria-invalid="true" @enderror>
                    @error('addresses') <p class="field-error">{{ $message }}</p> @enderror
                </label>
                <label class="field">
                    <span>Subject</span>
                    <input type="text" name="subject" value="{{ old('subject', $template?->subject) }}" required maxlength="200" @error('subject') aria-invalid="true" @enderror>
                    @error('subject') <p class="field-error">{{ $message }}</p> @enderror
                </label>
                <label class="field">
                    <span>Message</span>
                    <textarea name="body" rows="10" required maxlength="10000" @error('body') aria-invalid="true" @enderror>{{ old('body', $template?->body) }}</textarea>
                    @error('body') <p class="field-error">{{ $message }}</p> @enderror
                </label>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><x-icon name="message" :size="16" /> Send</button>
            </div>
        </form>
    @else
        <p class="muted">You can see your sent emails here, but sending needs a tag that allows “Email: Send emails”.</p>
    @endcan

    <section class="access-section" aria-labelledby="sent-title">
        <h3 id="sent-title" class="sub-title">Sent by you</h3>
        @if ($sent->isEmpty())
            <p class="muted">Nothing sent yet.</p>
        @else
            <div class="card table-scroll">
                <table class="access-table">
                    <thead><tr><th>Subject</th><th>To</th><th>When</th></tr></thead>
                    <tbody>
                        @foreach ($sent as $email)
                            <tr>
                                <td>{{ $email->subject }}</td>
                                <td>{{ implode(', ', $email->to) }}</td>
                                <td><time datetime="{{ $email->created_at->toIso8601String() }}">{{ $email->created_at->diffForHumans() }}</time></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
