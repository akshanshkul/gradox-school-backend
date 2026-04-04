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
            
            // Check if trial is expired
            if ($school->subscription_expires_at && $school->subscription_expires_at->isPast()) {
                // Block all destructive/config operations, allow only critical GETs and logout
                if ($request->isMethod('POST') || $request->isMethod('PATCH') || $request->isMethod('DELETE') || $request->isMethod('PUT')) {
                    return response()->json([
                        'error' => 'SUBSCRIPTION_EXPIRED',
                        'message' => 'Institutional trial has expired. Please upgrade your plan to restore full administrative access.',
                        'plan_name' => $school->plan_name,
                        'expired_at' => $school->subscription_expires_at->toDateTimeString()
                    ], 403);
                }
            }
        }
        
        return $next($request);
    }
}
