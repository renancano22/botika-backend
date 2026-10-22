<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Admin → User Accounts → Reset Password (resident accounts): the new temporary password, sent only to the
 * resident. Sent right away (never queued), so the password is not stored anywhere on the server.
 */
class TemporaryPasswordMail extends Mailable
{
    public function __construct(public string $name, public string $password, public string $expires) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'BulanBotikaCare: your temporary password');
    }

    public function content(): Content
    {
        $e = fn (string $v) => e($v);
        return new Content(htmlString: <<<HTML
            <div style="font-family:Arial,sans-serif;font-size:15px;color:#1f2d25;line-height:1.5">
              <p>Hello {$e($this->name)},</p>
              <p>Your BulanBotikaCare password was reset by the administrator of Botika ng Bayan Bulan.
                 Use this temporary password to log in:</p>
              <p style="font-size:20px;font-weight:bold;letter-spacing:1px;font-family:Consolas,monospace">{$e($this->password)}</p>
              <p>You will be asked to choose your own new password right after you log in.
                 This temporary password stops working on {$e($this->expires)} if it is not used.</p>
              <p>Do not share this password with anyone. If you did not ask for a password reset, please contact Botika ng Bayan Bulan.</p>
            </div>
            HTML);
    }
}
