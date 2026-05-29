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
