<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Services\StudentLimitService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Daily cron: scans every active school's student usage and emails the
 * school's administrator(s) when they cross the WARN_PERCENT threshold
 * (currently 80 %). The flag column `student_limit_warning_sent_at` debounces
 * the notification — we only send once per 7 days per school so an admin
 * who hovers at 82 % for three months doesn't get 90 daily emails.
 *
 * Schedule via app/Console/Kernel.php:
 *   $schedule->command('limits:check')->dailyAt('06:00');
 */
class CheckStudentLimits extends Command
{
    protected $signature = 'limits:check
        {--force : Send the email even if we already sent one in the last 7 days}
        {--school= : Only check a single school (slug or id)}';

    protected $description = 'Email school admins when student usage crosses 80% of plan limit';

    public function handle(StudentLimitService $svc): int
    {
        $query = School::with('plan')->whereNotNull('plan_id');
        if ($s = $this->option('school')) {
            $query->where(function ($q) use ($s) {
                $q->where('slug', $s)->orWhere('id', is_numeric($s) ? (int) $s : -1);
            });
        }
        $schools = $query->get();

        $emailed = 0;
        $skipped = 0;
        foreach ($schools as $school) {
            $snap = $svc->snapshot($school);
            $percent = $snap['students']['percent'];
            $state = $snap['students']['state'];

            if ($state === 'ok') {
                $skipped++;
                continue;
            }

            // Debounce: only one email per 7-day window unless --force.
            if (!$this->option('force')
                && $school->student_limit_warning_sent_at
                && $school->student_limit_warning_sent_at->gt(now()->subDays(7))) {
                $this->line("  ⏭  {$school->name} ({$percent}%) — already notified " . $school->student_limit_warning_sent_at->diffForHumans());
                $skipped++;
                continue;
            }

            $sent = $this->emailAdmins($school, $snap);
            if ($sent > 0) {
                $school->forceFill(['student_limit_warning_sent_at' => now()])->save();
                $emailed++;
                $this->info("  ✉  {$school->name} ({$percent}%, {$state}) — emailed $sent admin(s)");
            } else {
                $this->warn("  ⚠  {$school->name} ({$percent}%) — no admin user with an email found");
            }
        }

        $this->info("Done. Emailed: $emailed school(s); skipped: $skipped.");
        return self::SUCCESS;
    }

    private function emailAdmins(School $school, array $snap): int
    {
        $adminRoleIds = Role::where('school_id', $school->id)
            ->whereIn('slug', ['administrator', 'admin', 'super-admin'])
            ->pluck('id');

        $admins = User::where('school_id', $school->id)
            ->whereIn('role_id', $adminRoleIds)
            ->where('status', 'active')
            ->whereNotNull('email')
            ->get(['id', 'name', 'email']);

        if ($admins->isEmpty()) return 0;

        $count = $snap['students']['count'];
        $limit = $snap['students']['effective_limit'];
        $percent = $snap['students']['percent'];
        $planName = $snap['plan']['name'] ?? 'your current';

        $subject = $percent >= 100
            ? "Student cap reached — {$school->name}"
            : "Approaching student cap — {$school->name}";

        $body = "Hi,\n\n"
            . ($percent >= 100
                ? "Your school has reached the student cap on the {$planName} plan. New student admissions will be blocked until you upgrade or request a bump."
                : "You're using {$percent}% of your student cap on the {$planName} plan.")
            . "\n\nCurrent usage: $count / $limit students."
            . "\n\nUpgrade options are on the platform: log in to {$school->slug} dashboard → Settings → Subscription."
            . "\n\nIf you need a one-off bump (e.g. you only need 20 more students for this term), reply to this email and we'll arrange it.";

        $sentTo = 0;
        foreach ($admins as $admin) {
            try {
                Mail::raw($body, function ($m) use ($admin, $subject) {
                    $m->to($admin->email)->subject($subject);
                });
                $sentTo++;
            } catch (\Throwable $e) {
                Log::error('Student limit warning email failed', [
                    'school_id' => $school->id,
                    'admin_id' => $admin->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        return $sentTo;
    }
}
