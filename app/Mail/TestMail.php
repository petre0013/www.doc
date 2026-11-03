<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A "your SMTP works" message, sent by the setup wizard and the admin Email
 * settings tab. Styled like the backup report (mail.test mirrors
 * mail.backup-report) so all of the app's mail looks consistent.
 */
class TestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $fromAddress,
        public string $fromName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->fromName),
            subject: '[' . config('app.name') . '] Email settings test',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.test',
            with: ['appName' => config('app.name')],
        );
    }
}
