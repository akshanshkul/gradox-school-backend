<x-mail::message>
# Password Reset Notification

Hello {{ $user->name }},

Your institutional account password has been reset by the administrator. Please use the temporary credentials below to log in:

**New Password:** `{{ $newPassword }}`

<x-mail::button :url="config('app.url')">
Login to Dashboard
</x-mail::button>

> [!IMPORTANT]
> For security reasons, please change your password immediately after logging in.

Regards,
{{ config('app.name') }} Administration
</x-mail::message>
