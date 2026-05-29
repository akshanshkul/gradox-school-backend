<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Services\PlatformAuditService;
use Illuminate\Http\Request;

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

    public function enter(Request $request, $schoolId)
    {
        $school = School::findOrFail($schoolId);

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

        // Short-lived token (60 minutes). The `platform-impersonation:{id}` ability
        // is what LogImpersonationActions middleware looks for to know that
        // every write made with this token must be mirrored into the platform
        // audit log under the platform admin's name.
        $tokenName = 'platform-impersonation:' . $request->user()->id . ':' . now()->timestamp;
        $abilities = ['*', 'platform-impersonation:' . $request->user()->id];
        $token = $adminUser->createToken($tokenName, $abilities, now()->addMinutes(60))->plainTextToken;

        $this->audit->log(
            $request->user()->id,
            'school.impersonate',
            'school',
            $school->id,
            ['admin_user_id' => $adminUser->id, 'token_name' => $tokenName],
            $request
        );

        return response()->json([
            'school' => $school,
            'admin' => $adminUser,
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => 60 * 60,
            'impersonated_by' => [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
                'email' => $request->user()->email,
            ],
        ]);
    }
}
