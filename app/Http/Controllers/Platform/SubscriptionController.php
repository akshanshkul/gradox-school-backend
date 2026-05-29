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

        $school->update([
            'plan_name' => $plan->name,
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
}
