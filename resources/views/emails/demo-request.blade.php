<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>New Demo Request</title>
    <style>
        body {
            font-family: 'Plus Jakarta Sans', Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #364960;
            margin: 0;
            padding: 0;
            background-color: #F8FAFF;
        }
        .container {
            max-width: 600px;
            margin: 40px auto;
            background-color: #ffffff;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(27, 58, 107, 0.05);
            border: 1px solid #EEF2F9;
        }
        .header {
            background-color: #0F2347;
            padding: 40px;
            text-align: center;
        }
        .header h1 {
            color: #ffffff;
            margin: 0;
            font-size: 24px;
            font-weight: 900;
            letter-spacing: -0.5px;
        }
        .header p {
            color: #FF9A3C;
            margin: 10px 0 0;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 2px;
        }
        .content {
            padding: 40px;
        }
        .info-card {
            background-color: #F8FAFF;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 30px;
            border: 1px solid #EEF2F9;
        }
        .info-row {
            margin-bottom: 15px;
            display: flex;
            align-items: flex-start;
        }
        .info-row:last-child {
            margin-bottom: 0;
        }
        .label {
            font-size: 11px;
            font-weight: 900;
            color: #6B82A0;
            text-transform: uppercase;
            letter-spacing: 1px;
            width: 120px;
            flex-shrink: 0;
            padding-top: 4px;
        }
        .value {
            font-size: 16px;
            font-weight: 700;
            color: #1B3A6B;
        }
        .footer {
            padding: 30px 40px;
            background-color: #F8FAFF;
            text-align: center;
            border-top: 1px solid #EEF2F9;
        }
        .footer p {
            font-size: 12px;
            color: #6B82A0;
            margin: 0;
        }
        .btn {
            display: inline-block;
            background-color: #F07A1A;
            color: #ffffff;
            padding: 16px 32px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 900;
            font-size: 14px;
            margin-top: 20px;
            box-shadow: 0 4px 16px rgba(240, 122, 26, 0.3);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>GradoX Demo Request</h1>
            <p>New Lead Notification</p>
        </div>
        <div class="content">
            <p style="margin-top: 0; font-weight: 700; color: #1B3A6B;">Hello Team,</p>
            <p>You have received a new demo request from the GradoX landing page. Here are the details:</p>
            
            <div class="info-card">
                <div class="info-row">
                    <div class="label">Name</div>
                    <div class="value">{{ $data['name'] }}</div>
                </div>
                <div class="info-row">
                    <div class="label">School</div>
                    <div class="value">{{ $data['school_name'] }}</div>
                </div>
                <div class="info-row">
                    <div class="label">Mobile</div>
                    <div class="value">{{ $data['mobile'] }}</div>
                </div>
                <div class="info-row">
                    <div class="label">Email</div>
                    <div class="value">{{ $data['email'] }}</div>
                </div>
            </div>

            <p>Please contact the potential lead within the next 24 hours to schedule their walkthrough.</p>
            
            <div style="text-align: center;">
                <a href="mailto:{{ $data['email'] }}" class="btn">Reply to Lead →</a>
            </div>
        </div>
        <div class="footer">
            <p>© 2025 GradoX · Simple & Affordable School Management</p>
            <p style="margin-top: 5px;">This is an automated notification.</p>
        </div>
    </div>
</body>
</html>
