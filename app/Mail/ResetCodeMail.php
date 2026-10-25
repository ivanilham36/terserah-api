<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ResetCodeMail extends Mailable
{
    public function __construct(public string $name, public string $code)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Kode reset password Terserah!');
    }

    public function content(): Content
    {
        $name = e($this->name);

        return new Content(htmlString: "<p>Halo {$name},</p>"
            . "<p>Kode untuk mereset password Terserah! kamu:</p>"
            . "<p style=\"font-size:28px;letter-spacing:6px;font-weight:bold\">{$this->code}</p>"
            . '<p>Kode berlaku 15 menit. Kalau bukan kamu yang meminta, abaikan saja email ini.</p>');
    }
}
