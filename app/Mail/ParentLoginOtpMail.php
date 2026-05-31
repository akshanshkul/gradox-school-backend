<?php

namespace App\Mail;

use App\Models\School;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * One-time-password email for parent login.
 *
 * The sender name is set per-school (uses the school's name when we know
 * which school the parent belongs to, otherwise falls back to "GradoX").
 * Replaces the old plaintext Mail::raw call which always showed "Laravel"
 * as the sender.
 */
class ParentLoginOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $otp;
    public ?School $school;
    public int $expiresMinutes;

    public function __construct(string $otp, ?School $school = null, int $expiresMinutes = 10)
    {
        $this->otp = $otp;
        $this->school = $school;
        $this->expiresMinutes = $expiresMinutes;
    }

    public function build()
    {
        $senderName = $this->school?->name ?: 'GradoX';
        $senderAddress = config('mail.from.address');

        return $this
            ->from($senderAddress, $senderName)
            ->subject($this->school?->name
                ? "{$this->school->name} — Parent Login OTP"
                : 'Your Parent Login OTP')
            ->view('emails.parent-login-otp', [
                'otp' => $this->otp,
                'school' => $this->school,
                'expiresMinutes' => $this->expiresMinutes,
                'senderName' => $senderName,
            ]);
    }
}
