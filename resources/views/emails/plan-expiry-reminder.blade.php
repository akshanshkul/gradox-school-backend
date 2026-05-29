<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $subjectLine }}</title>
    <style>
        body {
            font-family: 'Outfit', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #1e293b;
            margin: 0;
            padding: 0;
            background-color: #f8fafc;
        }
        .container {
            max-width: 600px;
            margin: 40px auto;
            background-color: #ffffff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 6px 24px rgba(15, 23, 42, 0.06);
            border: 1px solid #e2e8f0;
        }
        .header {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            padding: 36px 40px;
            text-align: left;
            color: white;
        }
        .header .label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            opacity: 0.85;
            margin: 0 0 8px;
        }
        .header h1 {
            font-size: 22px;
            font-weight: 700;
            margin: 0;
            letter-spacing: -0.02em;
        }
        .content {
            padding: 36px 40px;
        }
        .info-card {
            background: #f5f3ff;
            border: 1px solid #ede9fe;
            border-radius: 12px;
            padding: 20px 24px;
            margin: 24px 0;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            font-size: 14px;
        }
        .info-row .k {
            color: #6b7280;
            font-weight: 500;
        }
        .info-row .v {
            color: #1e293b;
            font-weight: 600;
        }
        .body-text {
            font-size: 15px;
            line-height: 1.65;
            color: #334155;
            white-space: pre-wrap;
        }
        .footer {
            padding: 24px 40px;
            background: #f8fafc;
            color: #94a3b8;
            font-size: 12px;
            text-align: center;
            border-top: 1px solid #e2e8f0;
        }
        .btn {
            display: inline-block;
            background-color: #7c3aed;
            color: #ffffff !important;
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            margin-top: 16px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <p class="label">Subscription notice</p>
            <h1>{{ $subjectLine }}</h1>
        </div>
        <div class="content">
            <p style="margin-top: 0; font-size: 16px;">Hi {{ $school->name }} team,</p>

            <div class="body-text">{{ $body }}</div>

            <div class="info-card">
                <div class="info-row">
                    <span class="k">Plan</span>
                    <span class="v">{{ $school->plan_name ?: '—' }}</span>
                </div>
                <div class="info-row">
                    <span class="k">Status</span>
                    <span class="v" style="text-transform: capitalize;">{{ $school->subscription_status ?: '—' }}</span>
                </div>
                <div class="info-row">
                    <span class="k">Expires on</span>
                    <span class="v">
                        {{ $school->subscription_expires_at ? \Carbon\Carbon::parse($school->subscription_expires_at)->format('d M Y') : '—' }}
                    </span>
                </div>
            </div>

            <p style="font-size: 14px; color: #475569;">
                Sign in to your dashboard at
                <a href="{{ url('/' . $school->slug . '/dashboard/subscription') }}" style="color: #7c3aed;">
                    /{{ $school->slug }}/dashboard/subscription
                </a>
                to renew or upgrade.
            </p>

            <div style="text-align: left;">
                <a class="btn" href="{{ url('/' . $school->slug . '/dashboard/subscription') }}">Renew plan</a>
            </div>
        </div>
        <div class="footer">
            <p style="margin: 0;">&copy; {{ date('Y') }} GradoX &middot; Smart School Management</p>
            <p style="margin: 4px 0 0;">Reminder sent by the platform team.</p>
        </div>
    </div>
</body>
</html>
