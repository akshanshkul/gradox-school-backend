<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\School;
use App\Models\SubscriptionPayment;
use App\Services\PlatformAuditService;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(private PlatformAuditService $audit)
    {
    }

    public function assignPlan(Request $request, $schoolId)
    {
        $school = School::findOrFail($schoolId);

        $data = $request->validate([
            'plan_id' => 'required|exists:plans,id',
            'expires_at' => 'nullable|date|after:now',
            'status' => 'nullable|in:trialing,active,past_due,suspended,expired',
        ]);

        $plan = Plan::findOrFail($data['plan_id']);

        // IMPORTANT: also update `plan_id` (the FK). The pre-existing code
        // only updated `plan_name` (the legacy string column kept for
        // back-compat). Every limit/usage helper added in the recent
        // pricing-plan work reads `$school->plan` — which loads via plan_id.
        // Without writing plan_id here the change was invisible: the usage
        // card kept showing the OLD plan's cap and pricing.
        $school->update([
            'plan_name' => $plan->name,
            'plan_id' => $plan->id,
            'subscription_status' => $data['status'] ?? 'active',
            'subscription_expires_at' => $data['expires_at'] ?? $this->computeExpiry($plan),
        ]);

        $this->audit->log(
            $request->user()->id,
            'subscription.assign_plan',
            'school',
            $school->id,
            ['plan_id' => $plan->id, 'plan_name' => $plan->name],
            $request
        );

        return response()->json(['school' => $school]);
    }

    public function extend(Request $request, $schoolId)
    {
        $school = School::findOrFail($schoolId);

        $data = $request->validate([
            'days' => 'nullable|integer|min:1|max:3650',
            'expires_at' => 'nullable|date|after:now',
        ]);

        $newExpiry = $data['expires_at']
            ?? ($school->subscription_expires_at && $school->subscription_expires_at->isFuture()
                ? $school->subscription_expires_at->copy()->addDays($data['days'] ?? 30)
                : now()->addDays($data['days'] ?? 30));

        $school->update([
            'subscription_expires_at' => $newExpiry,
            'subscription_status' => 'active',
        ]);

        $this->audit->log(
            $request->user()->id,
            'subscription.extend',
            'school',
            $school->id,
            ['new_expiry' => (string) $newExpiry],
            $request
        );

        return response()->json(['school' => $school]);
    }

    public function payments($schoolId)
    {
        $payments = SubscriptionPayment::where('school_id', $schoolId)
            ->orderByDesc('id')
            ->paginate(25);

        return response()->json($payments);
    }

    private function computeExpiry(Plan $plan): \Carbon\Carbon
    {
        return match ($plan->billing_cycle) {
            'annual' => now()->addYear(),
            'lifetime' => now()->addYears(50),
            default => now()->addMonth(),
        };
    }

    /**
     * Push the free-trial end date forward for a school. Independent of
     * `extend()` (which bumps subscription_expires_at for a paid plan).
     * Useful for schools that need a couple more weeks to onboard before
     * committing to a paid tier.
     */
    public function extendTrial(Request $request, $schoolId)
    {
        $school = School::findOrFail($schoolId);

        $data = $request->validate([
            'extra_days' => 'required|integer|min:1|max:365', // cap to a year
            'note' => 'nullable|string|max:500',
        ]);

        $current = $school->trial_extended_until ?: $school->subscription_expires_at?->toDateString() ?: now()->toDateString();
        $newDate = \Carbon\Carbon::parse($current)->addDays($data['extra_days']);

        $school->forceFill([
            'trial_extended_until' => $newDate->toDateString(),
            // Also reflect on subscription_expires_at so the school-side
            // CheckSubscription middleware sees the new window.
            'subscription_expires_at' => $newDate,
            'subscription_status' => 'trialing',
        ])->save();

        $this->audit->log(
            $request->user()->id,
            'school.trial.extend',
            'school',
            $school->id,
            ['extra_days' => $data['extra_days'], 'new_until' => $newDate->toDateString(), 'note' => $data['note'] ?? null],
            $request
        );

        return response()->json([
            'message' => 'Trial extended by ' . $data['extra_days'] . ' days.',
            'trial_extended_until' => $newDate->toDateString(),
            'school' => $school->fresh(),
        ]);
    }

    /**
     * Set or clear the per-school student-cap bump. The effective limit
     * = plan.max_students + override. Pass 0 to remove the bump.
     */
    public function setStudentLimitOverride(Request $request, $schoolId)
    {
        $school = School::findOrFail($schoolId);

        $data = $request->validate([
            'override' => 'required|integer|min:0|max:100000',
            'note' => 'nullable|string|max:500',
        ]);

        $previous = (int) ($school->student_limit_override ?? 0);
        $school->forceFill(['student_limit_override' => $data['override']])->save();

        $this->audit->log(
            $request->user()->id,
            'school.student_limit.override',
            'school',
            $school->id,
            ['previous' => $previous, 'new' => $data['override'], 'note' => $data['note'] ?? null],
            $request
        );

        return response()->json([
            'message' => $data['override'] === 0
                ? 'Student-limit override cleared.'
                : 'Student-limit override set to +' . $data['override'] . '.',
            'student_limit_override' => $data['override'],
            'school' => $school->fresh()->load('plan'),
        ]);
    }
}
