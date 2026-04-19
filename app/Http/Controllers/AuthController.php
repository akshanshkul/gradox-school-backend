<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\School;
use Illuminate\Http\Request;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'school_name' => 'required|string|max:255',
            'school_email' => 'required|email|unique:schools,email',
            'slug' => 'required|string|alpha_dash|max:255|unique:schools,slug',
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        return DB::transaction(function () use ($request) {
            $school = School::create([
                'name' => $request->school_name,
                'email' => $request->school_email,
                'slug' => $request->slug,
                'plan_name' => 'Grow',
                'subscription_status' => 'trialing',
                'subscription_expires_at' => now()->addMonth(),
            ]);

            $adminRole = Role::firstOrCreate(
                ['school_id' => $school->id, 'slug' => 'administrator'],
                [
                    'name' => 'Administrator',
                    'description' => 'Global administrative access.',
                    'permissions' => $this->getFullDefaultPermissions()
                ]
            );

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'school_id' => $school->id,
                'role_id' => $adminRole->id,
            ]);

            $token = $user->createToken('auth_token')->plainTextToken;

            return $this->successResponse([
                'user' => $user->load('school', 'role_relation', 'managedClasses'),
                'access_token' => $token,
                'token_type' => 'Bearer',
            ], 'Welcome! Your institute has been registered.');
        });
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        if ($user->status === 'exit') {
            throw ValidationException::withMessages([
                'email' => ['You are no longer part of this institute.'],
            ]);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => ['Your account is inactive.'],
            ]);
        }

        if (!Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }
        $token = $user->createToken('auth_token')->plainTextToken;

        return $this->successResponse([
            'user' => $user->load('school', 'role_relation', 'managedClasses'),
            'access_token' => $token,
            'token_type' => 'Bearer',
        ], 'Login successful');
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return $this->successResponse(null, 'Logged out successfully');
    }

    private function getFullDefaultPermissions()
    {
        $resources = ['academic', 'students', 'timetable', 'staff', 'system', 'blogs', 'courses', 'reports'];
        $actions = ['read', 'create', 'update', 'delete', 'export', 'import', 'publish', 'approve', 'archive', 'reject', 'restore'];
        
        $perms = [];
        foreach ($resources as $res) {
            foreach ($actions as $act) {
                $perms[$res][$act] = true;
            }
        }
        return $perms;
    }
}
