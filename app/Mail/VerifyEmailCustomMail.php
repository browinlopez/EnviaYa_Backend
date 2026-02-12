<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerifyEmailCustomMail extends Mailable
{
    use Queueable, SerializesModels;

     public function __construct(
        public string $actionUrl
    ) {}

    public function build()
    {
        return $this
            ->subject('Verifica tu cuenta en VeciPa’Ya')
            ->markdown('emails.verify-email', [
                'actionUrl' => $this->actionUrl
            ]);
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.verify-email',
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
