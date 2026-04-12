<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSubscription
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->school) {
            $school = $user->school;
            
            // Priority: School-specific grace days -> Environment default -> 0
            $graceDays = $school->grace_days > 0 ? (int) $school->grace_days : (int) env('SUBSCRIPTION_GRACE_DAYS', 0);
            $expiryDate = $school->subscription_expires_at;

            // Block all operations ONLY if expired AND past the grace period
            // We use copy() to avoid mutating the original model attribute
            if ($expiryDate && $expiryDate->copy()->addDays($graceDays)->isPast()) {
                return response()->json([
                    'error' => 'SUBSCRIPTION_EXPIRED',
                    'message' => 'Institutional trial has expired. Please upgrade your plan to restore full administrative access.',
                    'plan_name' => $school->plan_name,
                    'expired_at' => $school->subscription_expires_at->toDateTimeString()
                ], 403);
            }
        }
        
        return $next($request);
    }
}
