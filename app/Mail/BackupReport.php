<?php

namespace App\Mail;

use App\Models\Backup;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The post-backup notification (and the "send test email" message). Rendered
 * with the runtime-configured `backup` mailer in BackupNotifier — the SMTP
 * credentials and sender come from the admin's Backups settings, not the
 * app's default mailer.
 */
class BackupReport extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly ?Backup $backup = null,
        public readonly bool $isTest = false,
    ) {}

    public function envelope(): Envelope
    {
        $app = config('app.name');

        $subject = match (true) {
            $this->isTest                    => "[{$app}] Backup email test",
            $this->backup?->status === 'done' => "[{$app}] Backup completed successfully",
            default                          => "[{$app}] Backup failed",
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $ok = $this->isTest || $this->backup?->status === 'done';

        return new Content(
            view: 'mail.backup-report',
            with: [
                'isTest'  => $this->isTest,
                'backup'  => $this->backup,
                'appName' => config('app.name'),
                // Card top bar: sage for good news, terracotta for a failure.
                'accent'  => $ok ? '#4B6840' : '#B5573E',
            ],
        );
    }
}
