<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;

class RoleController extends Controller
{
    use ApiResponse;
    /**
     * Display a listing of the roles for the school.
     */
    public function index(Request $request)
    {
        $roles = Role::where('school_id', $request->user()->school_id)->get();
        return $this->successResponse($roles, 'Institutional roles retrieved');
    }

    /**
     * Store a newly created role in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'permissions' => 'required|array',
        ]);

        $role = Role::create([
            'school_id' => $request->user()->school_id,
            'name' => $request->name,
            'slug' => \Illuminate\Support\Str::slug($request->name),
            'description' => $request->description,
            'permissions' => $request->permissions,
        ]);

        return $this->successResponse($role, 'Role established successfully', 201);
    }

    /**
     * Update the specified role in storage.
     */
    public function update(Request $request, $id)
    {
        $role = Role::where('id', $id)->where('school_id', $request->user()->school_id)->firstOrFail();

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'permissions' => 'required|array',
        ]);

        $role->update([
            'name' => $request->name,
            'slug' => \Illuminate\Support\Str::slug($request->name),
            'description' => $request->description,
            'permissions' => $request->permissions,
        ]);

        return $this->successResponse($role, 'Role specifications synchronized');
    }

    /**
     * Remove the specified role from storage.
     */
    public function destroy(Request $request, $id)
    {
        $role = Role::where('id', $id)->where('school_id', $request->user()->school_id)->firstOrFail();
        
        // Ensure no users are assigned to this role before deleting
        if ($role->users()->count() > 0) {
            return $this->errorResponse('Cannot delete role that is currently assigned to users.', 422);
        }

        $role->delete();

        return $this->successResponse(null, 'Role purged from directory');
    }
}
