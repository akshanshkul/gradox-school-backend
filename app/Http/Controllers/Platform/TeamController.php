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

        $admin = PlatformAdmin::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'] ?? 'staff',
            'status' => 'active',
        ]);

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

        $admin->update($data);

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
