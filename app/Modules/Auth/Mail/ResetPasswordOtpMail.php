<?php

namespace App\Modules\Auth\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResetPasswordOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $otp;
    public string $recipientName;
    public int $expireMinutes;

    /**
     * Create a new message instance.
     */
    public function __construct(string $otp, string $recipientName = 'Quý khách', int $expireMinutes = 15)
    {
        $this->otp = $otp;
        $this->recipientName = $recipientName;
        $this->expireMinutes = $expireMinutes;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Mã xác thực đặt lại mật khẩu - T-ResMK',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.reset_password_otp',
            with: [
                'otp' => $this->otp,
                'recipientName' => $this->recipientName,
                'expireMinutes' => $this->expireMinutes,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}

