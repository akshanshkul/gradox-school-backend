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
            // Default new self-signups to the Free Trial plan if it exists.
            // Historically this wrote plan_name='Grow' (a plan that never
            // existed in the catalog), leaving plan_id NULL and breaking
            // every downstream limit / usage helper. The School model's
            // saving() hook now also auto-syncs name↔id, but we still
            // resolve here so the very first INSERT has both columns set.
            $trialPlan = \App\Models\Plan::where('slug', 'free-trial')
                ->orWhere('name', 'Free Trial')
                ->first();

            $school = School::create([
                'name' => $request->school_name,
                'email' => $request->school_email,
                'slug' => $request->slug,
                'plan_id' => $trialPlan?->id,
                'plan_name' => $trialPlan?->name ?? 'Free Trial',
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
                'user' => $user->load([
                    'school' => function ($q) {
                        $q->select('id', 'name', 'slug', 'logo_path');
                    },
                    'role_relation',
                    'managedClasses' => function ($q) {
                        $q->select('id', 'grade_id', 'section_id', 'class_teacher_id', 'school_id')
                            ->with(['grade:id,name', 'section:id,name']);
                    }
                ]),
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
            'school_slug' => 'nullable|string|max:255',
        ]);

        $query = User::where('email', $request->email);

        if ($request->filled('school_slug')) {
            $query->whereHas('school', function ($q) use ($request) {
                $q->where('slug', $request->school_slug);
            });
        }

        $user = $query->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        // If the request came from a branded school login URL (e.g. /demo-school-1/login),
        // make sure the user actually belongs to THAT school. Prevents accidentally
        // logging into another school by typing in a different school's credentials.
        // Generic error message to avoid leaking which school an email belongs to.
        if ($request->filled('school_slug')) {
            $userSlug = optional($user->school)->slug;
            if (!$userSlug || strcasecmp($userSlug, $request->school_slug) !== 0) {
                throw ValidationException::withMessages([
                    'email' => ['Invalid credentials.'],
                ]);
            }
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

        // Suspension gate: non-admins can't log in when the school is suspended.
        // Admins (administrator / admin / super-admin / incharge) keep access so
        // they can see the suspension notice and contact the platform to renew.
        // The platform's own impersonation flow doesn't go through this route
        // (it mints tokens server-side), so it's not affected.
        $school = $user->school;
        if ($school && $school->subscription_status === 'suspended') {
            $adminSlugs = ['administrator', 'admin', 'super-admin', 'incharge'];
            $roleSlug = $user->role_relation?->slug ?? null;
            if (!in_array($roleSlug, $adminSlugs, true)) {
                throw ValidationException::withMessages([
                    'email' => ['This school has been temporarily suspended. Please contact your school administrator.'],
                ]);
            }
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return $this->successResponse([
            'user' => $user->load([
                'school' => function ($q) {
                    $q->select('id', 'name', 'slug', 'logo_path');
                },
                'role_relation',
                'managedClasses' => function ($q) {
                    $q->select('id', 'grade_id', 'section_id', 'class_teacher_id', 'school_id')
                        ->with(['grade:id,name', 'section:id,name']);
                }
            ]),
            'access_token' => $token,
            'token_type' => 'Bearer',
        ], 'Login successful');
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return $this->successResponse(null, 'Logged out successfully');
    }

    public function me(Request $request)
    {
        $user = $request->user()->load([
            'school' => function ($q) {
                $q->select('id', 'name', 'slug', 'logo_path');
            },
            'role_relation',
            'managedClasses' => function ($q) {
                $q->select('id', 'grade_id', 'section_id', 'class_teacher_id', 'school_id')
                    ->with(['grade:id,name', 'section:id,name']);
            }
        ]);

        // Include the school's effective module set so any client that
        // calls /api/user (school admin web, teacher mobile app) gets
        // the enabled modules in the same payload — no extra request
        // needed on app boot. Resolved from the per-school cached
        // bitmap, so this adds zero query cost.
        $enabledModules = [];
        if ($user->school) {
            $full = \App\Models\School::find($user->school->id);
            if ($full) {
                $enabledModules = \App\Services\ModuleAccessService::for($full)->all();
            }
        }

        // Back-compat: the existing /api/user response was the user
        // object at the root. We preserve that shape and just APPEND
        // enabled_modules as an extra attribute (Laravel automatically
        // merges it into the model's array form). Older consumers that
        // do `response.data.name` continue to work; newer code can
        // read `response.data.enabled_modules`.
        $user->setAttribute('enabled_modules', $enabledModules);

        return $this->successResponse($user);
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
