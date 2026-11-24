<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Every email Fort Vale sends: a plain-text body, with a simple HTML version,
 * and optional attached files.
 *
 * `$files` is a list of ['path' => object key in the files disk, or
 * 'local' => a local file path; 'name' => file name; 'mime' => type; 'size' => bytes].
 */
class FortValeEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $emailSubject,
        public string $text,
        public Address $sender,
        public ?string $link = null,
        public array $files = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(from: $this->sender, subject: $this->emailSubject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.message', text: 'emails.message-text');
    }

    /**
     * Files from Fort Vale's storage (`path`) or just uploaded (`local`).
     *
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return array_map(fn (array $file): Attachment => (isset($file['path'])
            ? Attachment::fromStorageDisk('files', $file['path'])
            : Attachment::fromPath($file['local'])
        )->as($file['name'])->withMime($file['mime']), $this->files);
    }
}
