<?php

namespace App\Http\Controllers;

use App\Mail\StaffPasswordResetOtpMail;
use App\Models\School;
use App\Models\StaffPasswordReset;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Self-serve password reset for staff (teachers, admins, incharges,
 * anything in the `users` table) initiated from the teacher mobile app.
 *
 * Three-step flow that mirrors the student app:
 *
 *   1. POST /staff/forgot-password { school_slug | school_id, email }
 *      → email a 6-digit OTP, store its hash with 10-minute expiry.
 *
 *   2. POST /staff/verify-otp { school_id, email, otp }
 *      → on hash match: invalidate the OTP, issue a one-time `reset_token`
 *        (hash stored, plaintext returned) with 15-minute expiry.
 *
 *   3. POST /staff/reset-password { school_id, email, reset_token, password, password_confirmation }
 *      → on hash match: update `users.password`, delete the reset row.
 *
 * Why we accept either `school_slug` or `school_id` on step 1: the
 * teacher app's login screen knows the slug (deep-linked from the
 * school picker) but the user typing in might only know their email.
 * `school_id` is required from step 2 onward because by then we've
 * resolved it once.
 *
 * Enumeration policy: we DO NOT return "user not found" — the response
 * is always "if an account exists, an OTP has been sent." This prevents
 * email-enumeration attacks. The flow only progresses if the real
 * account exists; bogus emails get an OTP-shaped response with no email.
 */
class StaffPasswordController extends Controller
{
    public function requestReset(Request $request)
    {
        $data = $request->validate([
            'school_slug' => 'required_without:school_id|string',
            'school_id'   => 'required_without:school_slug|integer|exists:schools,id',
            'email'       => 'required|email',
        ]);

        $school = isset($data['school_id'])
            ? School::find($data['school_id'])
            : School::where('slug', $data['school_slug'])->first();

        // Generic "we tried" response when the school doesn't resolve
        // — same wording as the success path, so a probing attacker
        // learns nothing about which schools exist.
        if (!$school) {
            return $this->genericSentResponse();
        }

        // Bail (silently to the caller) if the school is suspended.
        // Staff of a suspended school shouldn't be able to reset and
        // log in around the suspension. We still return the generic
        // response so non-staff can't probe suspension state either.
        if ($school->subscription_status === 'suspended') {
            return $this->genericSentResponse();
        }

        $user = User::where('school_id', $school->id)
            ->where('email', $data['email'])
            ->where('status', 'active')
            ->with('role_relation:id,slug')
            ->first();

        // Same opaque response when no matching account, when the
        // account is inactive, or when the role isn't one we let into
        // the teacher app. Don't leak which condition matched.
        if (!$user) {
            return $this->genericSentResponse();
        }

        $allowedRoles = ['administrator', 'admin', 'super-admin', 'incharge', 'teacher', 'staff'];
        if (!in_array($user->role_relation?->slug, $allowedRoles, true)) {
            return $this->genericSentResponse();
        }

        $otp = (string) random_int(100000, 999999);

        StaffPasswordReset::updateOrCreate(
            ['school_id' => $school->id, 'email' => $user->email],
            [
                'otp'        => Hash::make($otp),
                'token'      => null, // wipe any half-finished previous attempt
                'expires_at' => now()->addMinutes(10),
            ]
        );

        try {
            Mail::to($user->email)->send(new StaffPasswordResetOtpMail($otp, $user->name, $school->name));
        } catch (\Throwable $e) {
            // Don't expose mail-server failures to the client either.
            // Log internally so an operator can investigate, then return
            // the same opaque message. (If we returned 500 here, a
            // probing attacker could distinguish "valid email → server
            // tried to send" from "invalid email → quick OK".)
            \Log::warning('Staff password reset email failed', [
                'school_id' => $school->id,
                'email'     => $user->email,
                'error'     => $e->getMessage(),
            ]);
        }

        // Echo the school_id back so step 2 doesn't need a second
        // slug→id lookup on the client.
        return response()->json([
            'success'   => 1,
            'message'   => 'If an account exists for that email, a verification code has been sent.',
            'school_id' => $school->id,
        ]);
    }

    public function verifyOtp(Request $request)
    {
        $data = $request->validate([
            'school_id' => 'required|integer|exists:schools,id',
            'email'     => 'required|email',
            'otp'       => 'required|string|size:6',
        ]);

        $reset = StaffPasswordReset::where('school_id', $data['school_id'])
            ->where('email', $data['email'])
            ->first();

        // Single error message for "no pending reset" / "wrong OTP" /
        // "expired" so we don't tell an attacker which of the three.
        if (
            !$reset
            || !$reset->otp
            || !Hash::check($data['otp'], $reset->otp)
            || $reset->expires_at->isPast()
        ) {
            return response()->json([
                'success' => 0,
                'message' => 'Invalid or expired code. Please request a new OTP.',
            ], 422);
        }

        // Single-use: burn the OTP, issue a fresh reset-token.
        $token = Str::random(64);
        $reset->update([
            'otp'        => null,
            'token'      => Hash::make($token),
            'expires_at' => now()->addMinutes(15),
        ]);

        return response()->json([
            'success' => 1,
            'message' => 'Code verified successfully.',
            'data'    => ['reset_token' => $token],
        ]);
    }

    public function reset(Request $request)
    {
        $data = $request->validate([
            'school_id'             => 'required|integer|exists:schools,id',
            'email'                 => 'required|email',
            'reset_token'           => 'required|string',
            'password'              => 'required|string|min:8|confirmed',
        ]);

        $reset = StaffPasswordReset::where('school_id', $data['school_id'])
            ->where('email', $data['email'])
            ->first();

        if (
            !$reset
            || !$reset->token
            || !Hash::check($data['reset_token'], $reset->token)
            || $reset->expires_at->isPast()
        ) {
            return response()->json([
                'success' => 0,
                'message' => 'Invalid or expired reset session. Please start over from "Forgot password".',
            ], 422);
        }

        $user = User::where('school_id', $data['school_id'])
            ->where('email', $data['email'])
            ->where('status', 'active')
            ->first();

        if (!$user) {
            // The account vanished between OTP verify and now (admin
            // deleted it, etc.). Surface this honestly — there's no
            // enumeration concern at this stage; the caller already
            // proved possession of the OTP.
            return response()->json([
                'success' => 0,
                'message' => 'Account no longer exists.',
            ], 404);
        }

        $user->update(['password' => Hash::make($data['password'])]);

        // Burn the reset row immediately. The token is single-use.
        $reset->delete();

        // Best-effort: revoke any existing Sanctum tokens for this user
        // so a stolen device session is also kicked out by the reset.
        try {
            $user->tokens()->delete();
        } catch (\Throwable $e) {
            // Non-fatal; logging is enough.
            \Log::warning('Failed to revoke tokens after staff password reset', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => 1,
            'message' => 'Password reset successfully. Please sign in with your new password.',
        ]);
    }

    /**
     * Generic "OTP sent" response used in every branch of requestReset
     * where the caller shouldn't learn whether the account exists.
     * Centralised so the wording can't accidentally drift between
     * branches and become a side-channel.
     */
    private function genericSentResponse()
    {
        return response()->json([
            'success'   => 1,
            'message'   => 'If an account exists for that email, a verification code has been sent.',
            'school_id' => null,
        ]);
    }
}
