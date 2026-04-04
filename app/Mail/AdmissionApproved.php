<?php

namespace App\Mail;

use App\Models\AdmissionApplication;
use App\Models\EmailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdmissionApproved extends Mailable
{
    use Queueable, SerializesModels;

    public $application;
    public $templateData;

    /**
     * Create a new message instance.
     */
    public function __construct(AdmissionApplication $application)
    {
        $this->application = $application;
        
        // Find template for admission_confirmation (Approved)
        $template = EmailTemplate::findBySlug('admission_confirmation', $application->school_id);
        
        if ($template) {
            $this->templateData = $template->render([
                'student_name' => $application->student_name,
                'class_name' => $application->schoolClass->grade->name ?? 'N/A',
                'admission_number' => $application->admission_number,
                'school_name' => $application->school->name ?? config('app.name'),
            ]);
        }
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $schoolName = $this->application->school->name ?? config('app.name');
        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address(config('mail.from.address'), $schoolName),
            subject: $this->templateData['subject'] ?? 'Admission Confirmed!',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.layout',
            with: [
                'content' => $this->templateData['content_html'] ?? 'Congratulations! Your admission has been approved.',
                'school' => $this->application->school
            ]
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
