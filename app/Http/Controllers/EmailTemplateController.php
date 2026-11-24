<?php

namespace App\Http\Controllers;

use App\Models\EmailTemplate;
use App\Services\Email\Mailer;
use App\Support\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Email templates. The built-in ones are the ticket emails; people can add
 * their own for the compose page and the `email/send` action step.
 */
class EmailTemplateController extends Controller
{
    public function index(): View
    {
        return view('emails.templates.index', ['templates' => EmailTemplate::orderBy('name')->get()->sortBy(fn (EmailTemplate $template): int => $template->isBuiltIn() ? 0 : 1)->values()]);
    }

    public function create(): View
    {
        return view('emails.templates.form', ['template' => new EmailTemplate]);
    }

    public function store(Request $request): RedirectResponse
    {
        $template = EmailTemplate::create($this->validated($request));

        return redirect()->route('email-templates.index')->with('status', "Template “{$template->name}” created.");
    }

    public function edit(EmailTemplate $emailTemplate): View
    {
        return view('emails.templates.form', ['template' => $emailTemplate]);
    }

    public function update(Request $request, EmailTemplate $emailTemplate): RedirectResponse
    {
        $emailTemplate->update($this->validated($request, $emailTemplate));

        return redirect()->route('email-templates.index')->with('status', "Template “{$emailTemplate->name}” saved.");
    }

    public function destroy(EmailTemplate $emailTemplate): RedirectResponse
    {
        if ($emailTemplate->isBuiltIn()) {
            throw ValidationException::withMessages(['name' => 'Built-in templates are used for ticket emails and can’t be deleted.']);
        }

        $emailTemplate->delete();

        return redirect()->route('email-templates.index')->with('status', "Template “{$emailTemplate->name}” deleted.");
    }

    /**
     * Sends the template to yourself, with example values filled in.
     */
    public function test(EmailTemplate $emailTemplate, Mailer $mailer): RedirectResponse
    {
        $me = CurrentUser::get();
        $values = [
            'ticket' => ['id' => 12345, 'subject' => 'Example ticket', 'status' => 'Open', 'priority' => 'High', 'category' => 'IT', 'url' => route('tickets.index'), 'requester' => $me->username, 'assignee' => $me->username],
            'actor' => ['name' => $me->name, 'username' => $me->username],
            'reply' => ['body' => 'This is an example reply.'],
            'inputs' => [],
        ];

        $sent = $mailer->send([strtolower($me->email) => $me->name], $emailTemplate->subject, $emailTemplate->body, $values, 'compose', $me, null, Mailer::templateAttachments($emailTemplate));

        return back()->with('status', $sent ? "Test sent to {$me->email}." : 'The test couldn’t be sent; check the mail settings in .env.');
    }

    /**
     * @return array{name: string, subject: string, body: string}
     */
    private function validated(Request $request, ?EmailTemplate $template = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique(EmailTemplate::class)->ignore($template)],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
        ]);
    }
}
