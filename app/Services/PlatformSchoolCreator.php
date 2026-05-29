<?php

namespace App\Services;

use App\Models\Role;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Creates a new School together with its first administrator User and role.
 * Mirrors the public AuthController::register flow but is invoked by a
 * platform admin from the SaaS control panel rather than via self-signup.
 */
class PlatformSchoolCreator
{
    public function create(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $school = School::create([
                'name' => $data['school_name'],
                'email' => $data['school_email'],
                'slug' => $data['slug'],
                'contact_number' => $data['contact_number'] ?? null,
                'address' => $data['address'] ?? null,
                'plan_name' => $data['plan_name'] ?? 'Grow',
                'subscription_status' => $data['subscription_status'] ?? 'trialing',
                'subscription_expires_at' => $data['subscription_expires_at'] ?? now()->addMonth(),
                'grace_days' => $data['grace_days'] ?? 0,
            ]);

            $adminRole = Role::firstOrCreate(
                ['school_id' => $school->id, 'slug' => 'administrator'],
                [
                    'name' => 'Administrator',
                    'description' => 'Global administrative access.',
                    'permissions' => $this->defaultAdminPermissions(),
                ]
            );

            $admin = User::create([
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                'password' => Hash::make($data['admin_password']),
                'school_id' => $school->id,
                'role_id' => $adminRole->id,
                'status' => 'active',
            ]);

            return ['school' => $school, 'admin' => $admin];
        });
    }

    /**
     * Mirrors AuthController::getFullDefaultPermissions(). Kept in sync manually
     * to avoid coupling the platform layer to the user-facing auth controller.
     */
    private function defaultAdminPermissions(): array
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
