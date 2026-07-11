<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;

class EmailTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $templates = [
            [
                'slug' => 'admission_request',
                'school_id' => null, // Global Default
                'name' => 'Admission Request Received',
                'subject' => 'Application Received: {{student_name}}',
                'content_html' => '<h1>Application Received!</h1><p>Dear Parent,</p><p>We have successfully received the admission application for <strong>{{student_name}}</strong> in <strong>{{class_name}}</strong>.</p><p>Our admissions team will review the details and connect with you soon regarding the next steps.</p><p>Thank you for choosing {{school_name}}.</p>',
                'placeholders' => ['student_name', 'class_name', 'school_name'],
                'is_system' => true
            ],
            [
                'slug' => 'admission_confirmation',
                'school_id' => null, // Global Default
                'name' => 'Admission Confirmation (Approved)',
                'subject' => 'Admission Confirmed: {{student_name}}',
                'content_html' => '<h1>Welcome to {{school_name}}!</h1><p>Dear Parent,</p><p>We are delighted to inform you that the admission for <strong>{{student_name}}</strong> has been <strong>Approved</strong>.</p><p>Your unique Admission Number is: <strong class="accent">{{admission_number}}</strong></p><p>Please use this number for all future communications and fee payments.</p><p>We look forward to welcoming you to the {{school_name}} family.</p>',
                'placeholders' => ['student_name', 'class_name', 'admission_number', 'school_name'],
                'is_system' => true
            ],
            [
                'slug' => 'welcome_team_member',
                'school_id' => null, // Global Default
                'name' => 'Internal Welcome Email',
                'subject' => 'Welcome to the Team, {{staff_name}}!',
                'content_html' => '<h1>Welcome Aboard!</h1><p>Hello {{staff_name}},</p><p>We are absolutely thrilled to welcome you to the <strong>{{school_name}}</strong> community. Your journey with us as a {{staff_role}} starts now.</p><p>We look forward to achieving great things together!</p>',
                'placeholders' => ['staff_name', 'staff_role', 'school_name'],
                'is_system' => true
            ],
            [
                'slug' => 'password_reset',
                'school_id' => null, // Global Default
                'name' => 'Security: Password Reset',
                'subject' => 'Reset Your Institution Password',
                'content_html' => '<h1>Password Reset Request</h1><p>Hi {{user_name}},</p><p>We received a request to reset your password for your <strong>{{school_name}}</strong> portal account. Please use the button below to secure your identity.</p><p><a href="{{reset_url}}" class="button">Update Password</a></p><p>If you didn\'t request this, you can safely ignore this email.</p>',
                'placeholders' => ['user_name', 'school_name', 'reset_url'],
                'is_system' => true
            ],
            // --- Templates the codebase actively sends but had no defaults ---
            [
                'slug' => 'parent_login_otp',
                'school_id' => null,
                'name' => 'Parent Login OTP',
                'subject' => 'Your {{school_name}} login code',
                'content_html' => '<h1>Login Code</h1><p>Hi there,</p><p>Use the one-time code below to sign in to the <strong>{{school_name}}</strong> Parent Portal.</p><p style="font-size:32px;letter-spacing:8px;font-weight:bold;text-align:center;margin:24px 0;color:#4f46e5">{{otp}}</p><p>This code expires in <strong>{{expiry_minutes}} minutes</strong>. If you did not request it, ignore this email and your account stays safe.</p>',
                'placeholders' => ['school_name', 'otp', 'expiry_minutes'],
                'is_system' => true,
            ],
            [
                'slug' => 'fee_receipt',
                'school_id' => null,
                'name' => 'Fee Receipt',
                'subject' => 'Payment received — Receipt #{{receipt_no}}',
                'content_html' => '<h1>Payment Confirmed</h1><p>Dear {{parent_name}},</p><p>We have received your payment of <strong>₹{{amount}}</strong> towards <strong>{{fee_type}}</strong> for <strong>{{student_name}}</strong> ({{admission_number}}).</p><p>Receipt number: <strong>{{receipt_no}}</strong><br>Payment date: {{payment_date}}</p><p>Thank you for the prompt payment.</p><p>— Accounts Office, {{school_name}}</p>',
                'placeholders' => ['parent_name', 'amount', 'fee_type', 'student_name', 'admission_number', 'receipt_no', 'payment_date', 'school_name'],
                'is_system' => true,
            ],
            [
                'slug' => 'circular_notice',
                'school_id' => null,
                'name' => 'Circular / Notice',
                'subject' => '{{circular_title}} — {{school_name}}',
                'content_html' => '<h1>{{circular_title}}</h1><p>Dear Parent,</p><div>{{circular_body}}</div><p style="margin-top:24px;color:#64748b;font-size:13px">— {{school_name}}</p>',
                'placeholders' => ['circular_title', 'circular_body', 'school_name'],
                'is_system' => true,
            ],
            [
                'slug' => 'student_attendance_alert',
                'school_id' => null,
                'name' => 'Attendance Alert',
                'subject' => '{{student_name}} marked {{status}} today',
                'content_html' => '<h1>Attendance Update</h1><p>Dear {{parent_name}},</p><p>Your child <strong>{{student_name}}</strong> ({{class_name}}) was marked <strong>{{status}}</strong> on {{date}}.</p><p>If you believe this is an error, please contact the class teacher.</p><p>— {{school_name}}</p>',
                'placeholders' => ['parent_name', 'student_name', 'class_name', 'status', 'date', 'school_name'],
                'is_system' => true,
            ],
        ];

        foreach ($templates as $template) {
            EmailTemplate::updateOrCreate(
                ['slug' => $template['slug'], 'school_id' => null],
                $template
            );
        }
    }
}
