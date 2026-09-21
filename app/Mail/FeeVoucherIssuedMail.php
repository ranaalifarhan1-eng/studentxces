<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FeeVoucherIssuedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $schoolName,
        public readonly string $studentName,
        public readonly string $admissionNo,
        public readonly string $classSection,
        public readonly string $academicYear,
        public readonly string $challanNo,
        public readonly string $billingLabel,
        public readonly string $feeHeads,
        public readonly string $grossAmount,
        public readonly string $concessionAmount,
        public readonly string $amountPayable,
        public readonly string $dueDate,
        public readonly string $recipientRole = 'student', // 'student' or 'guardian'
        public readonly ?string $recipientName = null
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Fee Voucher Issued: {$this->billingLabel} — {$this->schoolName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.fee_voucher_issued',
            text: 'emails.fee_voucher_issued_plain'
        );
    }
}
