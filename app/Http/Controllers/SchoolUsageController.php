<?php

namespace App\Http\Controllers;

use App\Services\StudentLimitService;
use Illuminate\Http\Request;

/**
 * Returns the plan + usage snapshot for the currently-authenticated school.
 * Powers the school-side usage banner / upgrade modal, and is also used by
 * the school admin dashboard's "Subscription" card.
 *
 * Auth: regular `auth:sanctum` (school user). We pull the school via the
 * user's school_id so cross-tenant snooping isn't possible even if a
 * caller passes an `?id=` hint (we ignore it).
 */
class SchoolUsageController extends Controller
{
    /** Roles allowed to see plan / limit / billing data. Teachers, students,
     *  and other staff are intentionally excluded — billing concerns are
     *  for the school's administration only, and exposing usage numbers to
     *  every staff member is a soft privacy issue. */
    private const ADMIN_ROLE_SLUGS = ['administrator', 'admin', 'super-admin', 'incharge'];

    public function __construct(private StudentLimitService $svc)
    {
    }

    /** Refuse the call when the caller isn't a school admin. */
    private function ensureAdmin(Request $request): ?\Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        $roleSlug = $user->role_relation?->slug ?? null;
        if (!in_array($roleSlug, self::ADMIN_ROLE_SLUGS, true)) {
            return response()->json([
                'error' => 'FORBIDDEN_NOT_ADMIN',
                'message' => 'Plan and billing details are visible to administrators only.',
            ], 403);
        }
        return null;
    }

    public function show(Request $request)
    {
        if ($r = $this->ensureAdmin($request)) return $r;

        $school = $request->user()->school()->with('plan')->first();
        if (!$school) {
            return response()->json(['message' => 'No school for current user.'], 404);
        }
        return response()->json($this->svc->snapshot($school));
    }

    /**
     * Lets the school admin signal "we want to upgrade" — fires off a
     * platform notification + email so the SaaS owner can reach out.
     * This is the CTA target of the upgrade modal that appears at 100 %.
     */
    public function requestUpgrade(Request $request)
    {
        // Same role gate as show() — only admins can initiate upgrade
        // requests so a curious teacher can't pretend the school wants
        // to upgrade by hitting the endpoint directly.
        if ($r = $this->ensureAdmin($request)) return $r;

        $data = $request->validate([
            'requested_plan_slug' => 'nullable|string|max:40',
            'message' => 'nullable|string|max:1000',
        ]);

        $school = $request->user()->school()->with('plan')->first();
        if (!$school) {
            return response()->json(['message' => 'No school for current user.'], 404);
        }

        // Persisting as a "platform inquiry"-style row is overkill here; we
        // just send a notification email to the SaaS owner. The audit log
        // entry below captures it durably even if the email fails.
        $ownerEmail = config('mail.from.address') ?: env('PLATFORM_OWNER_EMAIL');
        $snapshot = $this->svc->snapshot($school);

        \Illuminate\Support\Facades\Log::info('school.upgrade_requested', [
            'school_id' => $school->id,
            'school_name' => $school->name,
            'current_plan' => $school->plan?->name,
            'requested_plan_slug' => $data['requested_plan_slug'] ?? null,
            'message' => $data['message'] ?? null,
            'usage' => $snapshot['students'],
        ]);

        try {
            if ($ownerEmail) {
                \Illuminate\Support\Facades\Mail::raw(
                    "School \"{$school->name}\" (slug: {$school->slug}) has requested an upgrade.\n\n"
                    . "Current plan: " . ($school->plan?->name ?? '(none)') . "\n"
                    . "Requested plan: " . ($data['requested_plan_slug'] ?? '(not specified)') . "\n"
                    . "Current usage: {$snapshot['students']['count']} / "
                    . ($snapshot['students']['effective_limit'] ?? '∞')
                    . " ({$snapshot['students']['percent']}%)\n\n"
                    . "Message from admin:\n" . ($data['message'] ?? '(none)') . "\n"
                    . "\nContact: " . ($school->email ?? '(none)'),
                    function ($m) use ($ownerEmail, $school) {
                        $m->to($ownerEmail)->subject('Upgrade request — ' . $school->name);
                    }
                );
            }
        } catch (\Throwable $e) {
            // Don't fail the request just because mail is down — the audit
            // log row above is the system of record.
            \Illuminate\Support\Facades\Log::error('Upgrade request email failed', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'message' => 'Upgrade request sent. Our team will reach out shortly.',
        ]);
    }
}
