@extends('layouts.app')

@section('title', 'Email templates')

@section('content')
    <div class="page-head">
        <div>
            <h2 class="section-title">Email templates <x-icon name="message" :size="26" class="title-icon" /></h2>
            <p class="page-sub">Built-in templates are the automatic ticket emails. Your own templates can be picked on the Email page and in actions (<code>email/send</code> with <code>template:</code>).</p>
        </div>
        @can('email_templates.create')
            <a href="{{ route('email-templates.create') }}" class="btn btn-primary"><x-icon name="plus" :size="16" /> New template</a>
        @endcan
    </div>

    <div class="card table-scroll">
        <table class="access-table">
            <thead><tr><th>Template</th><th>Subject</th><th>Used for</th></tr></thead>
            <tbody>
                @foreach ($templates as $template)
                    <tr>
                        <td>
                            @can('email_templates.edit')
                                <a href="{{ route('email-templates.edit', $template) }}"><strong>{{ $template->name }}</strong></a>
                            @else
                                <strong>{{ $template->name }}</strong>
                            @endcan
                            @if ($template->isBuiltIn()) <span class="pill status-open">Built in</span> @endif
                        </td>
                        <td><code>{{ $template->subject }}</code></td>
                        <td class="muted">{{ $template->isBuiltIn() ? 'Automatic ticket email' : 'Email page and actions' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
