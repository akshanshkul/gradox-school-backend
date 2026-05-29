<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Mail\PlanExpiryReminderMail;
use App\Models\PlatformAuditLog;
use App\Models\School;
use App\Models\User;
use App\Services\PlatformAuditService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ReminderController extends Controller
{
    public function __construct(private PlatformAuditService $audit)
    {
    }

    public function send(Request $request, $schoolId)
    {
        $school = School::findOrFail($schoolId);

        $data = $request->validate([
            'subject' => 'nullable|string|max:200',
            'body' => 'nullable|string|max:5000',
            'channels' => 'nullable|array',
            'channels.*' => 'in:email,in_app',
        ]);

        $channels = $data['channels'] ?? ['email', 'in_app'];
        $subject = $data['subject'] ?: $this->defaultSubject($school);
        $body = $data['body'] ?: $this->defaultBody($school);

        $adminUsers = $this->schoolAdminUsers($school);

        $result = [
            'email_sent' => 0,
            'email_failed' => 0,
            'in_app_delivered' => 0,
            'recipient_emails' => [],
        ];

        if (in_array('in_app', $channels, true) && $adminUsers->isNotEmpty()) {
            $result['in_app_delivered'] = $this->createInAppNotifications($school, $adminUsers, $subject, $body);
        }

        if (in_array('email', $channels, true)) {
            $emails = $adminUsers->pluck('email')->filter()->unique()->values();
            if ($school->email && !$emails->contains($school->email)) {
                $emails->push($school->email);
            }
            $result['recipient_emails'] = $emails->all();

            foreach ($emails as $email) {
                try {
                    Mail::to($email)->send(new PlanExpiryReminderMail($school, $subject, $body));
                    $result['email_sent']++;
                } catch (\Throwable $e) {
                    $result['email_failed']++;
                    Log::error('Plan expiry reminder email failed', [
                        'school_id' => $school->id,
                        'to' => $email,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->audit->log(
            $request->user()->id,
            'reminder.send',
            'school',
            $school->id,
            [
                'channels' => $channels,
                'subject' => $subject,
                'admin_user_count' => $adminUsers->count(),
                'email_sent' => $result['email_sent'],
                'email_failed' => $result['email_failed'],
                'in_app_delivered' => $result['in_app_delivered'],
            ],
            $request
        );

        return response()->json([
            'school' => $school,
            'subject' => $subject,
            'body' => $body,
            'result' => $result,
        ]);
    }

    /**
     * Build the default reminder subject + body from the school's current
     * plan and expiry. Used when the platform admin doesn't customize.
     */
    public function history(Request $request, $schoolId)
    {
        School::findOrFail($schoolId);

        $perPage = min((int) $request->query('per_page', 25), 100);

        $logs = PlatformAuditLog::with('admin:id,name,email')
            ->where('action', 'reminder.send')
            ->where('target_type', 'school')
            ->where('target_id', $schoolId)
            ->orderByDesc('id')
            ->paginate($perPage);

        $logs->getCollection()->transform(function ($entry) {
            $d = $entry->details ?? [];
            return [
                'id' => $entry->id,
                'sent_at' => $entry->created_at,
                'sent_by' => $entry->admin ? [
                    'id' => $entry->admin->id,
                    'name' => $entry->admin->name,
                    'email' => $entry->admin->email,
                ] : null,
                'subject' => $d['subject'] ?? null,
                'channels' => $d['channels'] ?? [],
                'admin_user_count' => $d['admin_user_count'] ?? 0,
                'email_sent' => $d['email_sent'] ?? 0,
                'email_failed' => $d['email_failed'] ?? 0,
                'in_app_delivered' => $d['in_app_delivered'] ?? 0,
            ];
        });

        return response()->json($logs);
    }

    public function preview($schoolId)
    {
        $school = School::findOrFail($schoolId);

        $adminUsers = $this->schoolAdminUsers($school);
        $emails = $adminUsers->pluck('email')->filter()->unique()->values();
        if ($school->email && !$emails->contains($school->email)) {
            $emails->push($school->email);
        }

        return response()->json([
            'subject' => $this->defaultSubject($school),
            'body' => $this->defaultBody($school),
            'recipients' => $emails->all(),
            'admin_user_count' => $adminUsers->count(),
        ]);
    }

    private function schoolAdminUsers(School $school)
    {
        return User::where('school_id', $school->id)
            ->where('status', 'active')
            ->whereHas('role_relation', function ($q) {
                $q->whereIn('slug', ['administrator', 'super-admin', 'admin']);
            })
            ->get(['id', 'school_id', 'name', 'email']);
    }

    private function defaultSubject(School $school): string
    {
        $days = $this->daysUntilExpiry($school);
        if ($days === null) {
            return "Action needed: your {$school->name} subscription";
        }
        if ($days <= 0) {
            return "Your {$school->name} subscription has expired";
        }
        if ($days <= 7) {
            return "Reminder: your subscription expires in {$days} day" . ($days === 1 ? '' : 's');
        }
        return "Heads up: your subscription expires in {$days} days";
    }

    private function defaultBody(School $school): string
    {
        $days = $this->daysUntilExpiry($school);
        $planName = $school->plan_name ?: 'current plan';

        if ($days === null) {
            return "This is a quick note about your {$planName} subscription. Please log in to your dashboard to review your billing details and avoid any interruption.";
        }
        if ($days <= 0) {
            return "Your {$planName} subscription has lapsed. Renew it from your dashboard to restore administrative access for your team.";
        }
        if ($days === 1) {
            return "Your {$planName} subscription expires tomorrow. Renew before then to avoid any interruption for your school's staff and students.";
        }
        return "Just a reminder that your {$planName} subscription will expire in {$days} days. Renewing early ensures uninterrupted access for everyone at your school.";
    }

    private function daysUntilExpiry(School $school): ?int
    {
        if (!$school->subscription_expires_at) {
            return null;
        }
        return (int) ceil(Carbon::now()->diffInDays(Carbon::parse($school->subscription_expires_at), false));
    }

    private function createInAppNotifications(School $school, $adminUsers, string $subject, string $body): int
    {
        $rows = $adminUsers->map(function ($u) use ($school, $subject, $body) {
            return [
                'school_id' => $school->id,
                'notifiable_type' => User::class,
                'notifiable_id' => $u->id,
                'title' => $subject,
                'message' => $body,
                'type' => 'warning',
                'data' => json_encode([
                    'source' => 'plan_expiry_reminder',
                    'expires_at' => $school->subscription_expires_at,
                    'plan_name' => $school->plan_name,
                    'cta_path' => '/' . $school->slug . '/dashboard/subscription',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        })->all();

        if (!empty($rows)) {
            DB::table('notifications')->insert($rows);
        }
        return count($rows);
    }
}
