@extends('emails.layout')

@section('content')
    <h1>Welcome, <span class="accent">{{ $user->name }}</span>!</h1>
    <p>We are thrilled to have you as part of our institutional team. Your role has been confirmed as <strong>{{ ucfirst($user->role) }}</strong> for <strong>{{ $user->school->name ?? 'Gradox Academic Group' }}</strong>.</p>
    
    <div class="credential-box">
        <label class="credential-label">Your Dashboard Credentials</label>
        <div class="credential-item">
            <span class="credential-label">Email</span><br/>
            <span class="credential-value">{{ $user->email }}</span>
        </div>
        <div class="credential-item">
            <span class="credential-label">Temporary Password</span><br/>
            <span class="credential-value">{{ $passwordText }}</span>
        </div>
    </div>

    <p style="margin-top: 30px; font-size: 14px; text-align: center;">
        <a href="{{ config('app.url') }}/login" class="button">Access Your Portal</a>
    </p>

    <div class="divider"></div>

    <p style="font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;">
        Security Notice: Please update your password immediately after logging in for the first time.
    </p>
@endsection
