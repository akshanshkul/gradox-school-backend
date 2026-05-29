<?php

namespace App\Mail;

use App\Models\School;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PlanExpiryReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public School $school;
    public string $subjectLine;
    public string $body;

    public function __construct(School $school, string $subjectLine, string $body)
    {
        $this->school = $school;
        $this->subjectLine = $subjectLine;
        $this->body = $body;
    }

    public function build()
    {
        return $this
            ->subject($this->subjectLine)
            ->view('emails.plan-expiry-reminder', [
                'school' => $this->school,
                'body' => $this->body,
                'subjectLine' => $this->subjectLine,
            ]);
    }
}
