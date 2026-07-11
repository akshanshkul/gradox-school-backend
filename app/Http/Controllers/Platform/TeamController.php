<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Services\PlatformAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class TeamController extends Controller
{
    public function __construct(private PlatformAuditService $audit)
    {
    }

    public function index()
    {
        return response()->json([
            'admins' => PlatformAdmin::orderBy('id')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeOwner($request);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:platform_admins,email',
            'password' => 'required|string|min:8',
            'role' => 'nullable|in:owner,staff',
        ]);

        // `role` and `status` were intentionally removed from PlatformAdmin's
        // $fillable so a self-service code path (profile update etc.) can't
        // accidentally set them. We're inside the owner-gated team flow here,
        // so it's safe to use forceFill to bypass the mass-assignment guard.
        $admin = new PlatformAdmin();
        $admin->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
        $admin->forceFill([
            'role' => $data['role'] ?? 'staff',
            'status' => 'active',
        ]);
        $admin->save();

        $this->audit->log($request->user()->id, 'team.create', 'platform_admin', $admin->id, ['email' => $admin->email, 'role' => $admin->role], $request);

        return response()->json(['admin' => $admin], 201);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeOwner($request);
        $admin = PlatformAdmin::findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:platform_admins,email,' . $admin->id,
            'role' => 'sometimes|in:owner,staff',
            'status' => 'sometimes|in:active,disabled',
            'password' => 'sometimes|string|min:8',
        ]);

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        // Split the update so non-privileged fields go through the regular
        // mass-assign guard and privileged fields use forceFill explicitly.
        // Also: protect against owner-lockout — refuse a role change that
        // would leave zero active owners. Without this an owner can demote
        // themselves or the last peer to staff and nobody can manage the
        // team again.
        $maybeDemotingLastOwner =
            isset($data['role']) && $data['role'] !== 'owner' && $admin->role === 'owner';
        $maybeDisablingLastOwner =
            isset($data['status']) && $data['status'] !== 'active' && $admin->role === 'owner';

        if ($maybeDemotingLastOwner || $maybeDisablingLastOwner) {
            $activeOwners = PlatformAdmin::where('role', 'owner')
                ->where('status', 'active')
                ->where('id', '!=', $admin->id)
                ->count();
            if ($activeOwners === 0) {
                return response()->json([
                    'message' => 'At least one active owner must remain. Promote another admin to owner first.',
                ], 422);
            }
        }

        $regular = array_intersect_key($data, array_flip(['name', 'email', 'password']));
        $privileged = array_intersect_key($data, array_flip(['role', 'status']));
        if (!empty($regular)) $admin->fill($regular);
        if (!empty($privileged)) $admin->forceFill($privileged);
        $admin->save();

        $this->audit->log($request->user()->id, 'team.update', 'platform_admin', $admin->id, ['changed' => array_keys($data)], $request);

        return response()->json(['admin' => $admin]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeOwner($request);
        if ((int) $id === $request->user()->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }
        $admin = PlatformAdmin::findOrFail($id);

        // Same owner-lockout guard as ::update — deleting the last active
        // owner leaves the team unmanageable.
        if ($admin->role === 'owner' && $admin->status === 'active') {
            $otherActiveOwners = PlatformAdmin::where('role', 'owner')
                ->where('status', 'active')
                ->where('id', '!=', $admin->id)
                ->count();
            if ($otherActiveOwners === 0) {
                return response()->json([
                    'message' => 'Cannot delete the last active owner. Promote another admin first.',
                ], 422);
            }
        }

        $admin->delete();

        $this->audit->log($request->user()->id, 'team.delete', 'platform_admin', (int) $id, [], $request);

        return response()->json(['status' => 'ok']);
    }

    private function authorizeOwner(Request $request): void
    {
        if ($request->user()->role !== 'owner') {
            abort(403, 'Only owner-level platform admins can manage the team.');
        }
    }
}
