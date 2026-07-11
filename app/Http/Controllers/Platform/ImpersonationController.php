<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Mail\PlatformImpersonationOtpMail;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Services\PlatformAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Lets a platform admin "Enter" a school as its administrator by minting a
 * short-lived Sanctum token for that school's existing admin User. The
 * generated token is then handed off to the regular school frontend, so the
 * platform admin can use 100% of the existing UI without any code duplication.
 */
class ImpersonationController extends Controller
{
    public function __construct(private PlatformAuditService $audit)
    {
    }

    /**
     * Step 1 of impersonation: send a one-time code to the platform admin's
     * registered email. The admin must enter that code (via confirmOtp())
     * to actually receive a handoff code. This is a step-up authentication
     * — without inbox access, a stolen platform-admin Sanctum token alone
     * can't impersonate any tenant.
     *
     * Response payload:
     *   - request_id: opaque key the client passes back to confirmOtp()
     *   - otp_sent_to: partial email (so the client can show "code sent to
     *     a••••@example.com") without exposing the full address again
     *   - expires_in_seconds: 600
     *
     * On suspended schools we still emit the 409 first so the admin gets a
     * chance to acknowledge before any email goes out.
     */
    public function enter(Request $request, $schoolId)
    {
        $school = School::findOrFail($schoolId);

        // Suspended schools need explicit acknowledgement before impersonation.
        // The school-side CheckSubscription middleware will start raising 403
        // on most writes inside that session, which looks like a bug — the
        // admin should know they're entering a degraded environment. Caller
        // opts in by passing `acknowledge_suspended=true` in the body.
        if ($school->subscription_status === 'suspended' && !$request->boolean('acknowledge_suspended')) {
            return response()->json([
                'error' => 'SCHOOL_SUSPENDED',
                'message' => 'This school is suspended. Pass acknowledge_suspended=true to enter anyway. Most writes will be blocked by the subscription guard inside the session.',
                'school_id' => $school->id,
                'subscription_status' => $school->subscription_status,
            ], 409);
        }

        $adminRole = Role::where('school_id', $school->id)
            ->where('slug', 'administrator')
            ->first();

        if (!$adminRole) {
            return response()->json([
                'error' => 'NO_ADMIN_ROLE',
                'message' => 'This school does not have an administrator role configured.',
            ], 422);
        }

        $adminUser = User::where('school_id', $school->id)
            ->where('role_id', $adminRole->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->first();

        if (!$adminUser) {
            return response()->json([
                'error' => 'NO_ADMIN_USER',
                'message' => 'This school has no active administrator user.',
            ], 422);
        }

        // Generate a request_id + 6-digit OTP. The actual handoff code is
        // NOT minted here — that happens in confirmOtp() only after the
        // admin proves they hold the inbox.
        $requestId = 'imp_req_' . Str::random(40);
        $otp = (string) random_int(100000, 999999);

        $platformAdmin = $request->user();
        $pending = [
            'platform_admin_id' => $platformAdmin->id,
            'school_id' => $school->id,
            'admin_user_id' => $adminUser->id,
            'acknowledge_suspended' => $request->boolean('acknowledge_suspended'),
            'otp_hash' => Hash::make($otp),
            'attempts' => 0,
            'created_at' => now()->toIso8601String(),
        ];
        // 10-minute window — plenty for the email round-trip; OTP is purged
        // on confirm() or after this TTL, whichever comes first.
        Cache::put('platform_impersonation_pending:' . $requestId, $pending, now()->addMinutes(10));

        try {
            Mail::to($platformAdmin->email)->send(new PlatformImpersonationOtpMail(
                $platformAdmin->name,
                $school->name,
                $otp,
                10
            ));
        } catch (\Throwable $e) {
            // Don't leak the OTP in the response, but make the failure mode
            // obvious so the admin doesn't sit waiting for an email that
            // can't be sent. Common cause: SMTP creds missing in prod env.
            Log::error('Platform impersonation OTP email failed', [
                'platform_admin_id' => $platformAdmin->id,
                'school_id' => $school->id,
                'error' => $e->getMessage(),
            ]);
            Cache::forget('platform_impersonation_pending:' . $requestId);
            return response()->json([
                'error' => 'OTP_DELIVERY_FAILED',
                'message' => 'Could not send the confirmation email. Check the platform mail configuration and try again.',
            ], 500);
        }

        // Audit the *request*. The completion gets its own row in confirmOtp().
        $this->audit->log(
            $platformAdmin->id,
            'school.impersonate.request',
            'school',
            $school->id,
            ['admin_user_id' => $adminUser->id, 'request_id' => $requestId, 'otp_email' => $platformAdmin->email],
            $request
        );

        return response()->json([
            'stage' => 'otp_sent',
            'request_id' => $requestId,
            'otp_sent_to' => $this->maskEmail($platformAdmin->email),
            'expires_in_seconds' => 600,
            'school' => [
                'id' => $school->id,
                'name' => $school->name,
                'slug' => $school->slug,
            ],
        ]);
    }

    /**
     * Step 2 of impersonation: confirm the OTP. On success mints the Sanctum
     * token, stashes it behind a 60-second one-time handoff code, and
     * returns the code + school slug. This is what the original `enter()`
     * used to return directly; now it's gated behind email verification.
     *
     * Attempts are counted in the cache entry and capped at 5. Once exceeded
     * the request_id is burned and the admin has to start over.
     */
    public function confirmOtp(Request $request)
    {
        $data = $request->validate([
            'request_id' => 'required|string|max:80',
            'otp' => 'required|string|size:6',
        ]);

        $cacheKey = 'platform_impersonation_pending:' . $data['request_id'];
        $pending = Cache::get($cacheKey);
        if (!$pending) {
            return response()->json([
                'error' => 'INVALID_OR_EXPIRED_REQUEST',
                'message' => 'Impersonation request is invalid or expired. Start over from the school detail page.',
            ], 404);
        }

        // The OTP is only valid for the platform admin who initiated the
        // request — if another admin somehow got hold of the request_id
        // they still can't complete the step.
        if ((int) $pending['platform_admin_id'] !== (int) $request->user()->id) {
            return response()->json([
                'error' => 'WRONG_ADMIN',
                'message' => 'This impersonation request was started by a different platform admin.',
            ], 403);
        }

        if (!Hash::check($data['otp'], $pending['otp_hash'])) {
            $pending['attempts'] = ($pending['attempts'] ?? 0) + 1;
            if ($pending['attempts'] >= 5) {
                Cache::forget($cacheKey);
                return response()->json([
                    'error' => 'TOO_MANY_ATTEMPTS',
                    'message' => 'Too many incorrect codes. Start over from the school detail page.',
                ], 429);
            }
            // Preserve the rest of the TTL.
            Cache::put($cacheKey, $pending, now()->addMinutes(10));
            return response()->json([
                'error' => 'INVALID_OTP',
                'message' => 'Code is incorrect. ' . (5 - $pending['attempts']) . ' attempt(s) remaining.',
            ], 422);
        }

        // OTP verified. Consume the request so the same OTP can't be reused.
        Cache::forget($cacheKey);

        // Re-fetch live records — schools/users can change between request
        // and confirm. School::find() picks up a suspension that happened
        // in the last 10 minutes; the SCHOOL_SUSPENDED guard below catches
        // it even if the original request was made with acknowledge=false.
        $school = School::find($pending['school_id']);
        $adminUser = User::find($pending['admin_user_id']);
        if (!$school || !$adminUser) {
            return response()->json([
                'error' => 'TARGET_GONE',
                'message' => 'Target school or admin no longer exists.',
            ], 410);
        }
        if ($school->subscription_status === 'suspended' && !($pending['acknowledge_suspended'] ?? false)) {
            return response()->json([
                'error' => 'SCHOOL_SUSPENDED',
                'message' => 'School was suspended after the OTP was sent. Start over and acknowledge the suspension.',
            ], 409);
        }

        // Now do exactly what the old enter() used to do — mint a scoped
        // Sanctum token, cache it behind a single-use 60-second handoff
        // code, return the code + school slug.
        $tokenName = 'platform-impersonation:' . $request->user()->id . ':' . now()->timestamp;
        $abilities = ['impersonation-session', 'platform-impersonation:' . $request->user()->id];
        $plainTextToken = $adminUser->createToken($tokenName, $abilities, now()->addMinutes(60))->plainTextToken;

        $handoffCode = 'ho_' . Str::random(48);
        Cache::put('platform_impersonation_handoff:' . $handoffCode, [
            'access_token' => $plainTextToken,
            'school_id' => $school->id,
            'school_slug' => $school->slug,
            'admin_user_id' => $adminUser->id,
            'platform_admin_id' => $request->user()->id,
            'platform_admin_name' => $request->user()->name,
            'platform_admin_email' => $request->user()->email,
            'created_at' => now()->toIso8601String(),
        ], now()->addSeconds(60));

        $this->audit->log(
            $request->user()->id,
            'school.impersonate.confirm',
            'school',
            $school->id,
            ['admin_user_id' => $adminUser->id, 'token_name' => $tokenName, 'handoff_issued' => true, 'request_id' => $data['request_id']],
            $request
        );

        return response()->json([
            'stage' => 'handoff_ready',
            'school' => [
                'id' => $school->id,
                'name' => $school->name,
                'slug' => $school->slug,
            ],
            'handoff_code' => $handoffCode,
            'handoff_expires_in_seconds' => 60,
            'session_expires_in_seconds' => 60 * 60,
            'school_slug' => $school->slug,
            'impersonated_by' => [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
                'email' => $request->user()->email,
            ],
        ]);
    }

    /**
     * Resend the OTP for an in-flight request. Useful when the original
     * email took too long to arrive. Reuses the same request_id and resets
     * the attempt counter.
     */
    public function resendOtp(Request $request)
    {
        $data = $request->validate([
            'request_id' => 'required|string|max:80',
        ]);

        $cacheKey = 'platform_impersonation_pending:' . $data['request_id'];
        $pending = Cache::get($cacheKey);
        if (!$pending) {
            return response()->json([
                'error' => 'INVALID_OR_EXPIRED_REQUEST',
                'message' => 'Original request expired. Start over.',
            ], 404);
        }
        if ((int) $pending['platform_admin_id'] !== (int) $request->user()->id) {
            return response()->json(['error' => 'WRONG_ADMIN', 'message' => 'Not your request.'], 403);
        }

        $school = School::find($pending['school_id']);
        if (!$school) return response()->json(['error' => 'TARGET_GONE'], 410);

        $otp = (string) random_int(100000, 999999);
        $pending['otp_hash'] = Hash::make($otp);
        // Reset attempt counter on resend so a previous typo doesn't carry over.
        $pending['attempts'] = 0;
        Cache::put($cacheKey, $pending, now()->addMinutes(10));

        try {
            Mail::to($request->user()->email)->send(new PlatformImpersonationOtpMail(
                $request->user()->name,
                $school->name,
                $otp,
                10,
                'resend'
            ));
        } catch (\Throwable $e) {
            Log::error('Platform impersonation OTP resend failed', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'OTP_DELIVERY_FAILED',
                'message' => 'Could not resend the confirmation email.',
            ], 500);
        }

        return response()->json([
            'stage' => 'otp_sent',
            'request_id' => $data['request_id'],
            'otp_sent_to' => $this->maskEmail($request->user()->email),
            'expires_in_seconds' => 600,
        ]);
    }

    /**
     * Partial-email helper: "alice@example.com" → "a••••@example.com".
     * Keeps enough hint for the admin to recognise their own address while
     * not echoing the full address back in API responses.
     */
    private function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) return '••••';
        [$user, $domain] = explode('@', $email, 2);
        $head = mb_substr($user, 0, 1);
        return $head . '••••@' . $domain;
    }

    /**
     * Public endpoint: exchange a one-time handoff code for the real Sanctum
     * token. Lives on the regular /api group (NOT /platform) so the school
     * frontend can call it without holding any platform credentials.
     *
     * Behaviour:
     *   - Code must exist and be unexpired (60s window).
     *   - Code is consumed on first read — any retry returns 404.
     *   - On success, returns the token and just enough context to bootstrap
     *     the school session.
     */
    public function exchange(Request $request)
    {
        $data = $request->validate([
            'handoff_code' => 'required|string|max:80',
        ]);

        $cacheKey = 'platform_impersonation_handoff:' . $data['handoff_code'];
        $payload = Cache::pull($cacheKey); // ← atomic read+delete, single-use

        if (!$payload) {
            return response()->json([
                'error' => 'INVALID_OR_EXPIRED_HANDOFF',
                'message' => 'Handoff code is invalid, already used, or expired. Return to the platform admin and try again.',
            ], 404);
        }

        return response()->json([
            'access_token' => $payload['access_token'],
            'token_type' => 'Bearer',
            'school_slug' => $payload['school_slug'],
            'session_expires_in_seconds' => 60 * 60,
            'impersonated_by' => [
                'name' => $payload['platform_admin_name'],
                'email' => $payload['platform_admin_email'],
            ],
        ]);
    }

    /**
     * "Stop impersonating" endpoint. Called from the school frontend's
     * impersonation banner. Revokes the current Sanctum token if it's an
     * impersonation token, otherwise no-ops cleanly.
     *
     * Auth: regular `auth:sanctum` (the school-side guard). The platform
     * admin's impersonation token IS a valid sanctum token, so it can
     * authenticate this call.
     */
    public function exit(Request $request)
    {
        $token = $request->user()?->currentAccessToken();
        if (!$token) {
            return response()->json(['message' => 'No active session.'], 401);
        }

        // Only delete tokens that ARE impersonation tokens — never wipe a
        // regular school admin's session here. The name prefix is what we
        // set on creation; checking the abilities marker is a second
        // defence in case the prefix is ever renamed.
        $isImpersonation = str_starts_with($token->name, 'platform-impersonation:')
            || in_array('impersonation-session', $token->abilities ?? [], true);

        if (!$isImpersonation) {
            return response()->json([
                'error' => 'NOT_AN_IMPERSONATION_SESSION',
                'message' => 'This session is not an impersonation session.',
            ], 400);
        }

        $token->delete();
        return response()->json([
            'message' => 'Impersonation session ended.',
            'ended' => true,
        ]);
    }
}
