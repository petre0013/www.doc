<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ReminderDigest extends Mailable
{
    /** @param list<string> $lines */
    public function __construct(public string $heading, public array $lines) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[' . config('app.name') . "] {$this->heading}");
    }

    public function content(): Content
    {
        return new Content(text: 'mail.reminder-digest');
    }
}
