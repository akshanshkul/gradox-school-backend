<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class DemoStaffSeeder extends Seeder
{
    public function run(): void
    {
        $schoolId = 1;

        // --- Demo Teaching Staff ---
        $teachingStaff = [
            [
                'name' => 'Prof. Alan Turing',
                'email' => 'turing@antigravity.edu',
                'role' => 'teacher',
                'is_teaching' => true,
                'staff_subtype' => null,
                'profile_picture' => 'https://i.pravatar.cc/150?u=turing'
            ],
            [
                'name' => 'Dr. Marie Curie',
                'email' => 'curie@antigravity.edu',
                'role' => 'teacher',
                'is_teaching' => true,
                'staff_subtype' => null,
                'profile_picture' => 'https://i.pravatar.cc/150?u=curie'
            ],
            [
                'name' => 'Isaac Newton',
                'email' => 'newton@antigravity.edu',
                'role' => 'incharge',
                'is_teaching' => true,
                'staff_subtype' => null,
                'profile_picture' => 'https://i.pravatar.cc/150?u=newton'
            ],
        ];

        foreach ($teachingStaff as $staff) {
            User::create(array_merge($staff, [
                'password' => Hash::make('password'),
                'school_id' => $schoolId,
                'teacher_details' => [
                    'education' => [],
                    'specializations' => [],
                    'personal_email' => null
                ]
            ]));
        }

        // --- Demo Non-Teaching Staff ---
        $nonTeachingStaff = [
            [
                'name' => 'John Ledger',
                'email' => 'accounts@antigravity.edu',
                'role' => 'staff', 
                'is_teaching' => false,
                'staff_subtype' => 'Accountant',
                'profile_picture' => 'https://i.pravatar.cc/150?u=ledger'
            ],
            [
                'name' => 'Sarah Firewall',
                'email' => 'it.admin@antigravity.edu',
                'role' => 'staff',
                'is_teaching' => false,
                'staff_subtype' => 'IT Admin',
                'profile_picture' => 'https://i.pravatar.cc/150?u=firewall'
            ],
            [
                'name' => 'Nurse Nightingale',
                'email' => 'nurse@antigravity.edu',
                'role' => 'staff',
                'is_teaching' => false,
                'staff_subtype' => 'Nurse',
                'profile_picture' => 'https://i.pravatar.cc/150?u=nurse'
            ],
        ];

        foreach ($nonTeachingStaff as $staff) {
            User::create(array_merge($staff, [
                'password' => Hash::make('password'),
                'school_id' => $schoolId,
                'teacher_details' => [
                    'education' => [],
                    'specializations' => [],
                    'personal_email' => null
                ]
            ]));
        }
    }
}
