<?php

namespace App\Services\Workflows\Steps;

use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\Email\Mailer;
use App\Services\Files\FilePaths;
use App\Services\Workflows\RunContext;
use Illuminate\Validation\ValidationException;

/**
 * `email/send`: emails people (usernames) or addresses, with a template or a
 * subject and body. `${{ recipient.name }}` is filled in per recipient.
 * Personal actions need their owner to have "Email: Send emails".
 */
class EmailSendStep extends BaseStep
{
    public function __construct(private Mailer $mailer, private FilePaths $paths) {}

    protected function keys(): array
    {
        return ['to' => true, 'template' => false, 'subject' => false, 'body' => false, 'attachments' => false];
    }

    protected function checks(array $with, string $path): array
    {
        $errors = [];
        $to = $with['to'] ?? null;

        if ($to !== null && ! is_string($to) && ! (is_array($to) && array_is_list($to) && array_filter($to, 'is_string') === $to)) {
            $errors[] = "{$path}.to: must be a username or address, or a list of them.";
        } elseif (is_array($to) && count($to) > Mailer::MAX_RECIPIENTS) {
            $errors[] = "{$path}.to: at most ".Mailer::MAX_RECIPIENTS.' recipients.';
        }

        $hasTemplate = isset($with['template']);
        if ($hasTemplate && (isset($with['subject']) || isset($with['body']))) {
            $errors[] = "{$path}: use either template, or subject and body, not both.";
        } elseif (! $hasTemplate && (! isset($with['subject']) || ! isset($with['body']))) {
            $errors[] = "{$path}: needs a template, or both subject and body.";
        }

        $attachments = $with['attachments'] ?? null;
        if ($attachments !== null && ! (is_array($attachments) && array_is_list($attachments) && count($attachments) <= 10 && array_filter($attachments, 'is_string') === $attachments)) {
            $errors[] = "{$path}.attachments: must be a list of up to 10 file paths, e.g. [\"Library/Quality/Checklist.pdf\"].";
        }

        return [
            ...$errors,
            ...$this->plainText($with, 'template', $path, 60),
            ...$this->plainText($with, 'subject', $path, 200),
            ...$this->plainText($with, 'body', $path, 10000),
        ];
    }

    public function run(array $with, RunContext $context): array
    {
        if (! $context->workflow->isShared()) {
            $owner = User::find($context->workflow->ownerId);
            if ($owner === null || ! $owner->hasPermission('emails.send')) {
                throw new StepFailed('The owner of this action isn’t allowed to send emails (Email: Send emails).');
            }
        }

        $entries = is_array($with['to']) ? $with['to'] : preg_split('/[\s,;]+/', (string) $with['to'], -1, PREG_SPLIT_NO_EMPTY);
        ['recipients' => $recipients, 'invalid' => $invalid] = $this->mailer->resolve($entries);

        if ($invalid !== []) {
            throw new StepFailed('Not a person or email address: '.implode(', ', $invalid).'.');
        }
        if ($recipients === []) {
            throw new StepFailed('No one to send to after filling in placeholders.');
        }
        if (count($recipients) > Mailer::MAX_RECIPIENTS) {
            throw new StepFailed('At most '.Mailer::MAX_RECIPIENTS.' recipients.');
        }

        $values = $context->values();
        unset($values['secrets']);

        $files = $this->attachments((array) ($with['attachments'] ?? []), $context);

        if (isset($with['template'])) {
            $template = EmailTemplate::where('name', $with['template'])->first()
                ?? throw new StepFailed("No email template named \"{$with['template']}\".");
            [$subject, $body] = [$template->subject, $template->body];
            array_push($files, ...Mailer::templateAttachments($template));
        } else {
            // Already filled in by the runner, except recipient.*, which the mailer fills per person.
            [$subject, $body] = [(string) $with['subject'], (string) $with['body']];
            $values = [];
        }

        try {
            Mailer::assertAttachmentSize($files);
        } catch (ValidationException $tooBig) {
            throw new StepFailed($tooBig->getMessage());
        }

        $sent = $this->mailer->send($recipients, $subject, $body, $values, 'action', $context->actor, $context->ticket ? route('tickets.show', $context->ticket) : null, $files);

        if ($sent < count($recipients)) {
            throw new StepFailed("Sent to {$sent} of ".count($recipients).'; check the mail settings.');
        }

        return ['sent' => $sent, 'to' => implode(', ', array_keys($recipients))];
    }

    /**
     * Files named by path that the action may read (see RunContext::filesPerson).
     *
     * @param  list<string>  $paths
     * @return list<array{name: string, mime: string, size: int, path: string}>
     */
    private function attachments(array $paths, RunContext $context): array
    {
        if ($paths === []) {
            return [];
        }

        $person = $context->filesPerson(null);
        $files = [];
        foreach ($paths as $path) {
            $file = $this->paths->file((string) $path, $person);
            if ($file !== null && $file->folder?->isPersonal() && ($person === null || ! $context->mayUseMyFilesOf($person))) {
                throw new StepFailed("Can't attach \"{$path}\": shared actions may only use the My files of the person running them.");
            }
            if ($file === null || ! $this->paths->canRead($person, $file)) {
                throw new StepFailed("Can’t attach \"{$path}\": no such file, or this action may not read it.");
            }
            $files[] = Mailer::attachmentFor($file);
        }

        return $files;
    }
}
