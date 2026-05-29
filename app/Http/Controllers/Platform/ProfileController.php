<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\PlatformAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function __construct(private PlatformAuditService $audit)
    {
    }

    public function update(Request $request)
    {
        $admin = $request->user();

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:platform_admins,email,' . $admin->id,
        ]);

        $admin->update($data);

        $this->audit->log($admin->id, 'profile.update', 'platform_admin', $admin->id, ['changed' => array_keys($data)], $request);

        return response()->json(['admin' => $admin->fresh()]);
    }

    public function changePassword(Request $request)
    {
        $admin = $request->user();

        $data = $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        if (!Hash::check($data['current_password'], $admin->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $admin->forceFill(['password' => Hash::make($data['new_password'])])->save();

        $this->audit->log($admin->id, 'profile.change_password', 'platform_admin', $admin->id, [], $request);

        return response()->json(['status' => 'ok']);
    }
}
