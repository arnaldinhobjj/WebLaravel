<?php

namespace App\Ai\Skills\Abilities;

use App\Ai\Skills\Skill;
use App\Ai\Skills\SkillFailed;
use App\Ai\Skills\SkillResult;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\Email\Mailer;

class EmailSend extends Skill
{
    public function name(): string
    {
        return 'email.send';
    }

    public function title(): string
    {
        return 'Send an email';
    }

    public function description(): string
    {
        return 'Send an email as the person to usernames and/or addresses (each gets their own copy), with a subject and text or a template name. ${{ recipient.name }} in the text becomes each person\'s name. Always show the person the email and confirm before sending.';
    }

    public function properties(): array
    {
        return [
            'to' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => Mailer::MAX_RECIPIENTS, 'description' => 'Usernames and/or email addresses.'],
            'subject' => ['type' => 'string', 'maxLength' => 200],
            'text' => ['type' => 'string', 'maxLength' => 10000],
            'template' => ['type' => 'string', 'maxLength' => 60, 'description' => 'Instead of subject and text: an email template\'s name.'],
        ];
    }

    public function required(): array
    {
        return ['to'];
    }

    public function permissions(): array
    {
        return ['emails.send'];
    }

    public function changesThings(): bool
    {
        return true;
    }

    public function run(User $me, array $input): SkillResult
    {
        $mailer = app(Mailer::class);
        ['recipients' => $recipients, 'invalid' => $invalid] = $mailer->resolve(array_map('strval', $input['to']));
        if ($invalid !== []) {
            throw new SkillFailed('Not a person or email address: '.implode(', ', $invalid).'.');
        }
        if ($recipients === []) {
            throw new SkillFailed('No one to send to.');
        }

        $files = [];
        if (! empty($input['template'])) {
            $template = EmailTemplate::where('name', $input['template'])->first() ?? throw new SkillFailed("No email template named “{$input['template']}”.");
            [$subject, $body] = [$template->subject, $template->body];
            $files = Mailer::templateAttachments($template);
        } elseif (! empty($input['subject']) && ! empty($input['text'])) {
            [$subject, $body] = [$input['subject'], $input['text']];
        } else {
            throw new SkillFailed('Give a subject and text, or a template name.');
        }

        $sent = $mailer->send($recipients, $subject, $body, ['actor' => ['name' => $me->name, 'username' => $me->username]], 'action', $me, null, $files);
        if ($sent < count($recipients)) {
            throw new SkillFailed("Sent to {$sent} of ".count($recipients).'; the mail server refused the rest.');
        }

        return SkillResult::ok("Email sent to {$sent} ".str('recipient')->plural($sent).'.', ['to' => array_keys($recipients)]);
    }
}
