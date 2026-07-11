<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Self-serve OTP email for the teacher app's "Forgot password" flow.
 *
 * Distinct from `StaffPasswordResetMail` (which the school admin sends
 * with a new auto-generated plaintext password) — this one delivers a
 * 6-digit OTP that the staff member types into the app to prove email
 * ownership, then chooses their own new password. The plaintext password
 * never leaves the staff member's device.
 */
class StaffPasswordResetOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public $otp;
    public $staffName;
    public $schoolName;

    public function __construct($otp, $staffName, $schoolName = null)
    {
        $this->otp = $otp;
        $this->staffName = $staffName;
        $this->schoolName = $schoolName;
    }

    public function build()
    {
        $schoolLine = $this->schoolName
            ? "<p style='color: #64748b; margin-top: -8px;'>Account: {$this->schoolName}</p>"
            : '';

        return $this
            ->from(config('mail.from.address'), 'GradoX')
            ->subject('Your Password Reset OTP')
            ->html("
                <div style='font-family: Arial, sans-serif; padding: 20px; color: #333;'>
                    <h2 style='color: #4f46e5;'>Password Reset Request</h2>
                    <p>Hello <strong>{$this->staffName}</strong>,</p>
                    {$schoolLine}
                    <p>You requested a password reset for your teacher / staff account. Please use the following 6-digit One-Time Password (OTP) to continue:</p>
                    <div style='background: #f3f4f6; padding: 15px; border-radius: 8px; font-size: 24px; font-weight: bold; text-align: center; letter-spacing: 5px; color: #1e293b; margin: 20px 0;'>
                        {$this->otp}
                    </div>
                    <p>This code will expire in 10 minutes. If you did not request this, please ignore this email — your password will remain unchanged.</p>
                    <hr style='border: none; border-top: 1px solid #e5e7eb; margin: 20px 0;'>
                    <p style='font-size: 12px; color: #6b7280;'>Powered by Gradox School Management System</p>
                </div>
            ");
    }
}
