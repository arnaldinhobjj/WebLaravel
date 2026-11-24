<?php

namespace App\Services\Email;

use App\Mail\FortValeEmail;
use App\Models\AppSetting;
use App\Models\SentEmail;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Workflows\Expressions;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Sends Fort Vale emails: works out recipients, fills in `${{ … }}`
 * placeholders per recipient, uses the sender from Email settings, and keeps
 * a record of what was sent.
 */
class Mailer
{
    public const MAX_RECIPIENTS = 20;

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
     */
    public function send(array $recipients, string $subject, string $body, array $values, string $source, ?User $sender = null, ?string $link = null): int
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
                ));
                $sent++;
            } catch (Throwable $exception) {
                Log::warning("Couldn't send an email to {$address}: {$exception->getMessage()}");
            }
        }

        return $sent;
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
