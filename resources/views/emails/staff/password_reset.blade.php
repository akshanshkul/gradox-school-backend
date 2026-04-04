@extends('emails.layout')

@section('content')
    <h1>Password <span class="accent">Reset</span></h1>
    <p>Hello <strong>{{ $user->name }}</strong>,</p>
    <p>Your institutional account password has been reset by the administrator. Please use the temporary credentials below to log in to your dashboard.</p>
    
    <div class="credential-box">
        <label class="credential-label">New Temporary Password</label>
        <div class="credential-item">
            <span class="credential-value">{{ $newPassword }}</span>
        </div>
    </div>

    <p style="margin-top: 30px; text-align: center;">
        <a href="{{ config('app.url') }}/login" class="button">Login to Dashboard</a>
    </p>

    <div class="divider"></div>

    <p style="font-size: 12px; color: #ef4444; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em;">
        Critical: For security reasons, you must change this temporary password immediately after logging in.
    </p>
@endsection
