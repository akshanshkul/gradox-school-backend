<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class StudentPasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public $otp;
    public $studentName;

    /**
     * Create a new message instance.
     */
    public function __construct($otp, $studentName)
    {
        $this->otp = $otp;
        $this->studentName = $studentName;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->subject('Your Password Reset OTP')
                    ->html("
                        <div style='font-family: Arial, sans-serif; padding: 20px; color: #333;'>
                            <h2 style='color: #4f46e5;'>Password Reset Request</h2>
                            <p>Hello <strong>{$this->studentName}</strong>,</p>
                            <p>You requested a password reset for your student account. Please use the following 6-digit One-Time Password (OTP) to continue:</p>
                            <div style='background: #f3f4f6; padding: 15px; border-radius: 8px; font-size: 24px; font-weight: bold; text-align: center; letter-spacing: 5px; color: #1e293b; margin: 20px 0;'>
                                {$this->otp}
                            </div>
                            <p>This code will expire in 10 minutes. If you did not request this, please ignore this email.</p>
                            <hr style='border: none; border-top: 1px solid #e5e7eb; margin: 20px 0;'>
                            <p style='font-size: 12px; color: #6b7280;'>Powered by Gradox School Management System</p>
                        </div>
                    ");
    }
}
