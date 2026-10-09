<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StudentPortalCredentialsMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public readonly string $schoolName,
        public readonly string $studentName,
        public readonly string $username,
        public readonly string $tempPassword,
        public readonly string $loginUrl,
        public readonly ?string $admissionNo = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your Student Portal Login Credentials — {$this->schoolName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.student_portal_credentials',
        );
    }
}
