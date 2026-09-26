<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Expired-plan handling = READ-ONLY mode, not a lockout.
 *
 * Once a school's subscription (plus grace days) has lapsed:
 *   - Safe reads (GET / HEAD / OPTIONS) keep working, so staff can still
 *     view every record on the dashboard.
 *   - Writes (POST / PUT / PATCH / DELETE) are rejected with
 *     403 SUBSCRIPTION_EXPIRED, except the routes needed to pay / renew.
 *
 * Reads carry an `X-Subscription-Status: expired` header so the frontend
 * can show the "plan expired" banner without an extra request.
 */
class CheckSubscription
{
    /** Write routes that must keep working while expired (paying to renew). */
    private const ALLOWED_WHEN_EXPIRED = [
        'api/school/subscription/*',
        'api/school/upgrade-request',
        'api/logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $school = $request->user()?->school;

        if (!$school || !$this->isExpired($school)) {
            return $next($request);
        }

        if (!$request->isMethodSafe() && !$request->is(...self::ALLOWED_WHEN_EXPIRED)) {
            return response()->json([
                'error' => 'SUBSCRIPTION_EXPIRED',
                'read_only' => true,
                'message' => 'Your plan has expired. You can still view your data, but changes are disabled until you renew. Please make a payment or contact sales.',
                'plan_name' => $school->plan_name,
                'expired_at' => $school->subscription_expires_at->toDateTimeString(),
            ], 403);
        }

        $response = $next($request);
        $response->headers->set('X-Subscription-Status', 'expired');

        return $response;
    }

    private function isExpired($school): bool
    {
        $expiryDate = $school->subscription_expires_at;
        if (!$expiryDate) {
            return false;
        }

        // Priority: school-specific grace days -> environment default -> 0.
        // copy() so the model attribute isn't mutated.
        $graceDays = $school->grace_days > 0
            ? (int) $school->grace_days
            : (int) env('SUBSCRIPTION_GRACE_DAYS', 0);

        return $expiryDate->copy()->addDays($graceDays)->isPast();
    }
}
