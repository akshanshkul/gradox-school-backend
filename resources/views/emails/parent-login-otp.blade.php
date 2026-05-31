<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Parent Login OTP</title>
    <style>
        body { margin:0; padding:0; background:#f8fafc; font-family:'Outfit','Inter',-apple-system,Segoe UI,Roboto,sans-serif; color:#1e293b; }
        .container { max-width:520px; margin:32px auto; background:#fff; border-radius:16px; overflow:hidden; box-shadow:0 6px 24px rgba(15,23,42,0.06); border:1px solid #e2e8f0; }
        .header { background:linear-gradient(135deg,#7c3aed 0%,#6d28d9 100%); padding:28px 32px; color:#fff; }
        .header .eyebrow { font-size:11px; font-weight:700; letter-spacing:2px; text-transform:uppercase; opacity:.85; margin:0 0 6px; }
        .header h1 { font-size:20px; font-weight:700; margin:0; letter-spacing:-0.01em; }
        .content { padding:28px 32px; }
        p { font-size:14.5px; line-height:1.6; color:#334155; margin:0 0 14px; }
        .otp-box { background:#f5f3ff; border:1px dashed #c4b5fd; border-radius:12px; padding:22px; text-align:center; margin:20px 0; }
        .otp-code { font-family:'Menlo','Consolas',monospace; font-size:34px; font-weight:800; color:#5b21b6; letter-spacing:8px; }
        .meta { font-size:12px; color:#64748b; }
        .footer { padding:20px 32px; background:#f8fafc; border-top:1px solid #e2e8f0; color:#94a3b8; font-size:12px; text-align:center; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <p class="eyebrow">{{ $senderName }}</p>
            <h1>Parent Login Verification</h1>
        </div>
        <div class="content">
            <p>Hi parent,</p>
            <p>Use the one-time code below to sign in@if($school) to <strong>{{ $school->name }}</strong>'s parent portal@endif.</p>

            <div class="otp-box">
                <div class="otp-code">{{ $otp }}</div>
                <div class="meta" style="margin-top:10px;">Valid for {{ $expiresMinutes }} minutes</div>
            </div>

            <p class="meta">If you did not request this code, you can safely ignore this email — your account is secure.</p>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} {{ $senderName }}
            @if($senderName !== 'GradoX')
                &middot; Powered by GradoX
            @endif
        </div>
    </div>
</body>
</html>
