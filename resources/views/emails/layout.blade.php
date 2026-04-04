@php
    $school = $school ?? ($application->school ?? null);
    $emailSettings = $school->email_settings ?? [];
    
    $brandColor = $emailSettings['brand_color'] ?? ($school->theme_color ?? '#6366f1');
    $brandLogo = $emailSettings['logo_url'] ?? ($school->logo_path ?? null);
    $schoolName = $school->name ?? config('app.name');

    // New interactive branding tokens
    $emailBg = $emailSettings['bg_color'] ?? '#0f172a';
    $contentBg = $emailSettings['content_bg_color'] ?? '#1e293b';
    $textColor = $emailSettings['text_color'] ?? '#f1f5f9';
    $mutedText = $emailSettings['muted_text_color'] ?? '#94a3b8';
@endphp
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <title>{{ $subject ?? $schoolName }}</title>
    <style type="text/css">
        body {
            background-color: {{ $emailBg }};
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            margin: 0;
            padding: 0;
            width: 100% !important;
            -webkit-text-size-adjust: none;
            display: flex;
            justify-content: center;
        }
        .wrapper {
            background-color: {{ $emailBg }};
            padding: 20px;
            width: 100%;
            display: flex;
            justify-content: center;
        }
        .content {
            background-color: {{ $contentBg }};
            width: 100%;
            max-width: 600px;
            margin: 0 auto;
            border-radius: 24px;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.05);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        .header {
            padding: 40px;
            text-align: center;
        }
        .body {
            padding: 0 40px 40px;
            color: {{ $textColor }};
        }
        h1 {
            color: {{ $textColor }};
            font-size: 24px;
            font-weight: 800;
            margin-top: 0;
            text-align: left;
            letter-spacing: -0.025em;
        }
        p {
            color: {{ $mutedText }};
            font-size: 16px;
            line-height: 1.6;
            margin-top: 0;
            text-align: left;
        }
        .button {
            display: inline-block;
            background-color: {{ $brandColor }};
            color: #ffffff !important;
            padding: 14px 28px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-top: 25px;
            text-align: center;
        }
        .footer {
            padding: 30px;
            text-align: center;
            color: {{ $mutedText }};
            font-size: 12px;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            opacity: 0.7;
        }
        .accent {
            color: {{ $brandColor }};
        }
        .divider {
            height: 1px;
            background-color: rgba(255,255,255,0.05);
            margin: 30px 0;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="content">
            <div class="header">
                @if($brandLogo)
                    <img src="{{ $brandLogo }}" alt="{{ $schoolName }}" style="max-height: 50px; width: auto;" />
                @else
                    <div style="font-size: 28px; font-weight: 900; color: #ffffff; letter-spacing: -0.05em; font-style: italic; text-transform: uppercase;">{{ $schoolName }}</div>
                @endif
            </div>
            <div class="body">
                {!! $content ?? '' !!}
            </div>
            <div class="divider" style="margin: 0; background-color: {{ $brandColor }}; opacity: 0.3;"></div>
            <div class="footer">
                &copy; {{ date('Y') }} {{ $schoolName }} &bull; Secure Institution Management
            </div>
        </div>
    </div>
</body>
</html>
