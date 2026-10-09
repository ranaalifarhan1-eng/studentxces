<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ParentPortalCredentialsMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public readonly string $schoolName,
        public readonly string $guardianName,
        public readonly string $username,
        public readonly string $tempPassword,
        public readonly string $loginUrl,
        public readonly ?string $studentNames = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your Parent Portal Login Credentials — {$this->schoolName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.parent_portal_credentials',
        );
    }
}
