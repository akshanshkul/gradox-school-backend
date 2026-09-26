<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Services\PlatformAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private PlatformAuditService $audit)
    {
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $admin = PlatformAdmin::where('email', $request->email)->first();

        if (!$admin || !Hash::check($request->password, $admin->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        if (!$admin->isActive()) {
            throw ValidationException::withMessages([
                'email' => ['Your account is disabled.'],
            ]);
        }

        $admin->forceFill(['last_login_at' => now()])->save();

        $token = $admin->createToken('platform-admin', ['platform:*'])->plainTextToken;

        $this->audit->log($admin->id, 'auth.login', 'platform_admin', $admin->id, [], $request);

        return response()->json([
            'admin' => $admin,
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    public function sendOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $email = strtolower($request->email);

        $admin = PlatformAdmin::where('email', $email)->first();

        $genericResponse = response()->json([
            'status' => 'ok',
            'message' => 'If an active platform admin account exists for this email, an OTP has been sent.',
        ]);

        if (!$admin) {
            return $genericResponse;
        }

        if (!$admin->isActive()) {
            return response()->json([
                'message' => 'Your account is disabled.',
            ], 403);
        }

        $otp = (string) rand(100000, 999999);

        \App\Models\CommonOtp::updateOrCreate(
            ['identifier' => $email, 'type' => 'platform_admin_login'],
            [
                'otp' => Hash::make($otp),
                'expires_at' => now()->addMinutes(10),
            ]
        );

        if (config('app.env') === 'local') {
            \Illuminate\Support\Facades\Log::info("Platform Admin OTP for {$email}: {$otp}");
        }

        try {
            \Illuminate\Support\Facades\Mail::to($email)->send(
                new \App\Mail\PlatformAdminLoginOtpMail($admin->name, $otp, 10)
            );
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Platform admin OTP send failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            
            // In local environment, if email send fails but we successfully logged it, 
            // we can still return success to allow developers to log in using the logged OTP.
            if (config('app.env') === 'local') {
                return $genericResponse;
            }

            return response()->json([
                'message' => 'Failed to send OTP email. Please try again.',
            ], 500);
        }

        return $genericResponse;
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required|string|size:6',
        ]);

        $email = strtolower($request->email);
        $otp = $request->otp;

        $admin = PlatformAdmin::where('email', $email)->first();

        if (!$admin) {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or OTP.'],
            ]);
        }

        if (!$admin->isActive()) {
            return response()->json([
                'message' => 'Your account is disabled.',
            ], 403);
        }

        $otpRecord = \App\Models\CommonOtp::where('identifier', $email)
            ->where('type', 'platform_admin_login')
            ->first();

        if (!$otpRecord || !Hash::check($otp, $otpRecord->otp) || $otpRecord->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'otp' => ['Invalid or expired OTP.'],
            ]);
        }

        $otpRecord->delete();

        $admin->forceFill(['last_login_at' => now()])->save();

        $token = $admin->createToken('platform-admin', ['platform:*'])->plainTextToken;

        $this->audit->log($admin->id, 'auth.login', 'platform_admin', $admin->id, [], $request);

        return response()->json([
            'admin' => $admin,
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    public function logout(Request $request)
    {
        $admin = $request->user();
        $admin->currentAccessToken()->delete();

        $this->audit->log($admin->id, 'auth.logout', 'platform_admin', $admin->id, [], $request);

        return response()->json(['status' => 'ok']);
    }

    public function me(Request $request)
    {
        return response()->json([
            'admin' => $request->user(),
        ]);
    }
}
