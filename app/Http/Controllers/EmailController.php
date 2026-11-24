<?php

namespace App\Http\Controllers;

use App\Models\EmailTemplate;
use App\Models\SentEmail;
use App\Models\User;
use App\Services\Email\Mailer;
use App\Support\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The compose page: write an email to colleagues or any address, optionally
 * starting from a template.
 */
class EmailController extends Controller
{
    public function create(Request $request): View
    {
        $template = $request->integer('template') ? EmailTemplate::find($request->integer('template')) : null;
        $me = CurrentUser::get();

        return view('emails.compose', [
            'people' => User::orderBy('name')->get(),
            'templates' => EmailTemplate::orderBy('name')->get(),
            'template' => $template,
            'sent' => SentEmail::where('user_id', $me->id)->where('source', 'compose')->latest('id')->limit(20)->get(),
        ]);
    }

    public function store(Request $request, Mailer $mailer): RedirectResponse
    {
        $data = $request->validate([
            'people' => ['array'],
            'people.*' => ['integer', Rule::exists(User::class, 'id')],
            'addresses' => ['nullable', 'string', 'max:2000'],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
        ]);

        $entries = User::whereKey($data['people'] ?? [])->pluck('username')->all();
        array_push($entries, ...preg_split('/[\s,;]+/', (string) ($data['addresses'] ?? ''), -1, PREG_SPLIT_NO_EMPTY));

        ['recipients' => $recipients, 'invalid' => $invalid] = $mailer->resolve($entries);

        if ($invalid !== []) {
            throw ValidationException::withMessages(['addresses' => 'These aren’t valid email addresses: '.implode(', ', $invalid).'.']);
        }
        if ($recipients === []) {
            throw ValidationException::withMessages(['people' => 'Choose at least one person or type an email address.']);
        }
        if (count($recipients) > Mailer::MAX_RECIPIENTS) {
            throw ValidationException::withMessages(['addresses' => 'An email can go to at most '.Mailer::MAX_RECIPIENTS.' people.']);
        }

        $me = CurrentUser::get();
        $sent = $mailer->send($recipients, $data['subject'], $data['body'], ['actor' => ['name' => $me->name, 'username' => $me->username]], 'compose', $me);

        if ($sent < count($recipients)) {
            return back()->withInput()->with('status', "Sent to {$sent} of ".count($recipients).'. The rest couldn’t be delivered; check the mail settings in .env.');
        }

        return redirect()->route('emails.create')->with('status', 'Email sent to '.count($recipients).' '.str('recipient')->plural(count($recipients)).'.');
    }
}
