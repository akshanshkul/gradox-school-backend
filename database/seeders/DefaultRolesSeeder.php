<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\School;
use App\Models\Role;
use App\Models\User;

class DefaultRolesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $schools = School::all();

        foreach ($schools as $school) {
            // 1. Admin Role
            $adminRole = Role::updateOrCreate(
                ['school_id' => $school->id, 'slug' => 'administrator'],
                [
                    'name' => 'Administrator',
                    'description' => 'Full administrative access to all institutional modules.',
                    'permissions' => $this->getFullPermissions()
                ]
            );

            // 2. Teacher Role (Restricted by default)
            $teacherRole = Role::updateOrCreate(
                ['school_id' => $school->id, 'slug' => 'teacher'],
                [
                    'name' => 'Teacher',
                    'description' => 'Standard teaching faculty access (restricted administrative view).',
                    'permissions' => [
                        'profile' => ['read' => true, 'update' => true],
                    ]
                ]
            );

            // Assign role_id to existing users based on their string 'role'
            User::where('school_id', $school->id)->where('role', 'admin')->update(['role_id' => $adminRole->id]);
            User::where('school_id', $school->id)->where('role', 'teacher')->update(['role_id' => $teacherRole->id]);
        }
    }

    private function getFullPermissions()
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
