<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Every email Fort Vale sends: a plain-text body, with a simple HTML version.
 */
class FortValeEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $emailSubject,
        public string $text,
        public Address $sender,
        public ?string $link = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(from: $this->sender, subject: $this->emailSubject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.message', text: 'emails.message-text');
    }
}
