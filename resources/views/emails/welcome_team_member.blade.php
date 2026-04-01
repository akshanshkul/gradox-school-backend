<!DOCTYPE html>
<html>
<head>
    <title>Welcome to the Team!</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <h2>Welcome, {{ $user->name }}!</h2>
    <p>You have been added as a <strong>{{ ucfirst($user->role) }}</strong> for <strong>{{ $user->school->name ?? 'our school' }}</strong>.</p>
    
    <p>Here are your login credentials:</p>
    <ul>
        <li><strong>Email:</strong> {{ $user->email }}</li>
        <li><strong>Password:</strong> {{ $passwordText }}</li>
    </ul>

    <p style="margin-top: 20px;">
        <a href="{{ config('app.url') }}/login" style="background-color: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Login to your Dashboard</a>
    </p>

    <p style="margin-top: 30px; font-size: 12px; color: #777;">
        Please change your password after your first login.<br>
        If you did not expect this, please contact the administrator.
    </p>
</body>
</html>
