<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\School;
use App\Models\User;
use App\Models\Grade;
use App\Models\Section;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Classroom;
use App\Models\SchoolPeriod;
use App\Models\RoleWorkloadConfig;
use App\Models\TimetableEntry;
use Illuminate\Support\Facades\Hash;

class SchoolDemoSeeder extends Seeder
{
    public function run()
    {
        // 1. Create School
        $school = School::create([
            'name' => 'St. Antigravity Academy',
            'address' => '123 AI Boulevard, Tech City',
            'contact_number' => '+1 234 567 890',
            'email' => 'admin@antigravity.edu',
        ]);

        // 2. Create Users (Admin, Incharge, Teachers)
        $admin = User::create([
            'name' => 'Dr. Julian Thorne',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'school_id' => $school->id,
            'profile_picture' => 'https://i.pravatar.cc/150?u=admin@example.com',
        ]);

        $incharge = User::create([
            'name' => 'Sarah Vance',
            'email' => 'incharge@example.com',
            'password' => Hash::make('password'),
            'role' => 'incharge',
            'school_id' => $school->id,
            'profile_picture' => 'https://i.pravatar.cc/150?u=incharge@example.com',
        ]);

        $teachers = [];
        $teacherNames = [
            'Robert Miller',
            'Emma Wilson',
            'Michael Chen',
            'Sophia Rodriguez',
            'David Park',
            'Olivia Brown',
            'James Taylor',
            'Isabella Lee'
        ];

        foreach ($teacherNames as $name) {
            $email = strtolower(str_replace(' ', '.', $name)) . '@example.com';
            $teachers[] = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make('password'),
                'role' => 'teacher',
                'school_id' => $school->id,
                'profile_picture' => 'https://i.pravatar.cc/150?u=' . $email,
            ]);
        }

        // 3. Create Grades & Sections
        $grades = [];
        for ($i = 1; $i <= 10; $i++) {
            $grades[$i] = Grade::create(['name' => "Grade $i", 'school_id' => $school->id]);
        }

        $sections = [
            Section::create(['name' => 'A', 'school_id' => $school->id]),
            Section::create(['name' => 'B', 'school_id' => $school->id]),
        ];

        // 4. Create Classes (Grade + Section)
        $classes = [];
        foreach ($grades as $level => $grade) {
            foreach ($sections as $section) {
                $classes[] = SchoolClass::create([
                    'grade_id' => $grade->id,
                    'section_id' => $section->id,
                    'school_id' => $school->id,
                ]);
            }
        }

        // 5. Create Subjects
        $subjectData = [
            ['name' => 'Mathematics', 'code' => 'MATH'],
            ['name' => 'Physics', 'code' => 'PHY'],
            ['name' => 'Chemistry', 'code' => 'CHEM'],
            ['name' => 'English Literature', 'code' => 'ENG'],
            ['name' => 'Computer Science', 'code' => 'CS'],
            ['name' => 'History', 'code' => 'HIST'],
            ['name' => 'Geography', 'code' => 'GEO'],
        ];

        $subjects = [];
        foreach ($subjectData as $data) {
            $subjects[] = Subject::create(array_merge($data, ['school_id' => $school->id]));
        }

        // 6. Create Classrooms
        $roomTypes = ['Lecture Hall', 'Laboratory', 'Smart Room'];
        $classrooms = [];
        for ($i = 101; $i <= 110; $i++) {
            $classrooms[] = Classroom::create([
                'name' => "Room $i",
                'type' => $roomTypes[array_rand($roomTypes)],
                'capacity' => rand(30, 60),
                'school_id' => $school->id,
            ]);
        }

        // 7. Create School Periods (Standard Bell Schedule)
        $periods = [
            ['name' => 'Morning Assembly', 'start_time' => '08:00:00', 'end_time' => '08:15:00', 'type' => 'assembly', 'sort_order' => 1],
            ['name' => 'Period 1', 'start_time' => '08:15:00', 'end_time' => '09:10:00', 'type' => 'class', 'sort_order' => 2],
            ['name' => 'Period 2', 'start_time' => '09:10:00', 'end_time' => '10:05:00', 'type' => 'class', 'sort_order' => 3],
            ['name' => 'Short Break', 'start_time' => '10:05:00', 'end_time' => '10:20:00', 'type' => 'break', 'sort_order' => 4],
            ['name' => 'Period 3', 'start_time' => '10:20:00', 'end_time' => '11:15:00', 'type' => 'class', 'sort_order' => 5],
            ['name' => 'Period 4', 'start_time' => '11:15:00', 'end_time' => '12:10:00', 'type' => 'class', 'sort_order' => 6],
            ['name' => 'Lunch Break', 'start_time' => '12:10:00', 'end_time' => '13:00:00', 'type' => 'lunch', 'sort_order' => 7],
            ['name' => 'Period 5', 'start_time' => '13:00:00', 'end_time' => '13:55:00', 'type' => 'class', 'sort_order' => 8],
            ['name' => 'Period 6', 'start_time' => '13:55:00', 'end_time' => '14:50:00', 'type' => 'class', 'sort_order' => 9],
        ];

        foreach ($periods as $p) {
            SchoolPeriod::create(array_merge($p, ['school_id' => $school->id]));
        }

        // 8. Create Role Workload Configs
        RoleWorkloadConfig::create(['role_name' => 'teacher', 'min_classes_per_day' => 3, 'max_classes_per_day' => 6, 'school_id' => $school->id]);
        RoleWorkloadConfig::create(['role_name' => 'incharge', 'min_classes_per_day' => 1, 'max_classes_per_day' => 3, 'school_id' => $school->id]);
        RoleWorkloadConfig::create(['role_name' => 'admin', 'min_classes_per_day' => 0, 'max_classes_per_day' => 2, 'school_id' => $school->id]);

        // 9. Generate realistic Timetable Entries for Grade 9A
        $grade9A = $classes[16]; // Approximately Grade 9-A
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        $classPeriods = SchoolPeriod::where('school_id', $school->id)->where('type', 'class')->get();

        foreach ($days as $day) {
            foreach ($classPeriods as $period) {
                TimetableEntry::create([
                    'school_id' => $school->id,
                    'school_class_id' => $grade9A->id,
                    'subject_id' => $subjects[array_rand($subjects)]->id,
                    'user_id' => $teachers[array_rand($teachers)]->id,
                    'classroom_id' => $classrooms[array_rand($classrooms)]->id,
                    'day_of_week' => $day,
                    'start_time' => $period->start_time,
                    'end_time' => $period->end_time,
                    'is_active' => true,
                ]);
            }
        }
    }
}
