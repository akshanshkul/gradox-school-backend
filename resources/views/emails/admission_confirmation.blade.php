@extends('emails.layout')

@section('content')
    <h1>Application Received!</h1>
    <p>Dear <strong>{{ $application->parent_name ?? $application->student_name }}</strong>,</p>
    
    <p>Thank you for showing interest in joining <strong>{{ $application->school->name ?? 'our institution' }}</strong>. We are thrilled to have received the admission application for <strong>{{ $application->student_name }}</strong>.</p>
    
    <div class="credential-box" style="background-color: #0f172a; padding: 30px; border-radius: 20px; border: 1px solid #334155; margin: 25px 0;">
        <p style="margin: 0; color: #ffffff; font-weight: 700; font-size: 15px;">Next Steps:</p>
        <p style="margin: 10px 0 0 0; color: #94a3b8; font-size: 14px;">Our admissions team will review your application and get in touch with you shortly at <strong>{{ $application->phone }}</strong> or via this email address.</p>
    </div>

    <p>We look forward to potentially welcoming you to our academic community!</p>

    <div class="divider"></div>

    <p style="font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; text-align: center;">
        This is an automated confirmation of your submission. No further action is required at this time.
    </p>
@endsection
