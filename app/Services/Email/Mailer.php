<?php

namespace App\Services\Email;

use App\Mail\FortValeEmail;
use App\Models\AppSetting;
use App\Models\EmailTemplate;
use App\Models\SentEmail;
use App\Models\StoredFile;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Workflows\Expressions;
use App\Support\Attachables;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sends Fort Vale emails: works out recipients, fills in `${{ … }}`
 * placeholders per recipient, uses the sender from Email settings, and keeps
 * a record of what was sent.
 */
class Mailer
{
    public const MAX_RECIPIENTS = 20;

    /** Attachments per email, in total. */
    public const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;

    /**
     * Turns usernames and email addresses into recipients, keyed by address.
     *
     * @param  list<string>  $entries
     * @return array{recipients: array<string, string>, invalid: list<string>}
     */
    public function resolve(array $entries): array
    {
        $recipients = [];
        $invalid = [];

        foreach ($entries as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }

            if (str_contains($entry, '@')) {
                if (Validator::make(['email' => $entry], ['email' => 'email:rfc'])->fails()) {
                    $invalid[] = $entry;

                    continue;
                }
                $person = User::where('email', $entry)->first();
                $recipients[strtolower($entry)] = $person->name ?? $entry;

                continue;
            }

            $person = User::where('username', strtolower($entry))->first();
            if ($person === null) {
                $invalid[] = $entry;

                continue;
            }
            $recipients[strtolower($person->email)] = $person->name;
        }

        return ['recipients' => $recipients, 'invalid' => $invalid];
    }

    /**
     * Records the email, then sends one copy per recipient (so addresses aren't
     * shared and `${{ recipient.name }}` fits each one). Returns how many were sent.
     *
     * @param  array<string, string>  $recipients  address => name
     * @param  array<string, mixed>  $values  placeholder values
     * @param  list<array{name: string, mime: string, size: int, path?: string, local?: string}>  $files  see FortValeEmail
     */
    public function send(array $recipients, string $subject, string $body, array $values, string $source, ?User $sender = null, ?string $link = null, array $files = []): int
    {
        $recipients = array_slice($recipients, 0, self::MAX_RECIPIENTS, true);
        if ($recipients === []) {
            return 0;
        }

        SentEmail::create([
            'user_id' => $sender?->id,
            'source' => $source,
            'to' => array_keys($recipients),
            'subject' => mb_substr(self::oneLine((string) Expressions::render($subject, $values + ['recipient' => ['name' => '']])), 0, 200),
        ]);

        $sent = 0;
        foreach ($recipients as $address => $name) {
            $personal = $values + ['recipient' => ['name' => $name, 'email' => $address]];

            try {
                Mail::to(new Address($address, $name))->send(new FortValeEmail(
                    self::oneLine((string) Expressions::render($subject, $personal)),
                    (string) Expressions::render($body, $personal),
                    $this->sender(),
                    $link,
                    $files,
                ));
                $sent++;
            } catch (Throwable $exception) {
                Log::warning("Couldn't send an email to {$address}: {$exception->getMessage()}");
            }
        }

        return $sent;
    }

    /**
     * An attachment from a stored file (its current version).
     *
     * @return array{name: string, mime: string, size: int, path: string}
     */
    public static function attachmentFor(StoredFile $file): array
    {
        $version = $file->currentVersion;

        return ['name' => $file->name, 'mime' => $version->mime, 'size' => (int) $version->size, 'path' => $version->path];
    }

    /**
     * The files a template always sends with it.
     *
     * @return list<array{name: string, mime: string, size: int, path: string}>
     */
    public static function templateAttachments(EmailTemplate $template): array
    {
        return Attachables::filesFor('email_template', $template->id)
            ->filter(fn (StoredFile $file): bool => $file->currentVersion !== null)
            ->map(fn (StoredFile $file): array => self::attachmentFor($file))
            ->values()
            ->all();
    }

    /**
     * Refuses attachments over the per-email limit.
     *
     * @param  list<array{size: int}>  $files
     */
    public static function assertAttachmentSize(array $files, string $errorKey = 'attachments'): void
    {
        $total = array_sum(array_column($files, 'size'));
        if ($total > self::MAX_ATTACHMENT_BYTES) {
            throw ValidationException::withMessages([$errorKey => 'Attachments add up to '.number_format($total / 1048576, 1).' MB; an email can carry at most '.(self::MAX_ATTACHMENT_BYTES / 1048576).' MB.']);
        }
    }

    /**
     * Subjects are a single line; placeholder values could contain line breaks.
     */
    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * The sender set on the Email settings page, or MAIL_FROM_* from .env.
     */
    public function sender(): Address
    {
        return new Address(
            (string) (AppSetting::get('mail.from_address') ?: config('mail.from.address')),
            (string) (AppSetting::get('mail.from_name') ?: config('mail.from.name')),
        );
    }

    /**
     * Placeholder values for a ticket (`${{ ticket.subject }}` and so on).
     *
     * @return array<string, mixed>
     */
    public static function ticketValues(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'subject' => $ticket->subject,
            'status' => $ticket->status->label(),
            'priority' => $ticket->priority->label(),
            'category' => $ticket->categoryLabel(),
            'url' => route('tickets.show', $ticket),
            'requester' => $ticket->requester?->username,
            'assignee' => $ticket->assignee?->username,
        ];
    }
}
