<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Subject;
use App\Models\SchoolClass;
use App\Models\RoleWorkloadConfig;
use Illuminate\Http\Request;

class TimetableSchedulingController extends Controller
{
    public function getTimetableSchedulingData(Request $request)
    {
        $school = $request->user()->school;

        // 1. Fetch Subjects
        $subjects = $school->subjects()->get(['id', 'name']);

        // 2. Fetch Classes
        $classesRaw = $school->classes()
            ->with(['grade', 'section', 'subjects'])
            ->get();

        $classes = $classesRaw->map(function ($class) {
            return [
                'id' => $class->id,
                'name' => $class->grade->name . ($class->section ? " " . $class->section->name : ""),
                'class_teacher_id' => $class->class_teacher_id,
                'subjects' => $class->subjects->map(function ($subject) {
                    return [
                        'id' => $subject->id,
                        'lectures' => $subject->pivot->periods_per_week ?? 1,
                    ];
                })->toArray(),
            ];
        });

        // 3. Fetch Teachers and Workload Configs
        $teachersRaw = $school->users()
            ->where('is_teaching', true)
            ->where('status', 'active')
            ->get();

        $roleConfigs = RoleWorkloadConfig::where('school_id', $school->id)
            ->get()
            ->keyBy('role_name');

        $teachers = $teachersRaw->map(function ($teacher) use ($roleConfigs, $classesRaw, $subjects) {
            $config = $roleConfigs->get($teacher->role);

            $details = $teacher->teacher_details ?? [];
            $specializations = $details['specializations'] ?? [];

            $mapSpec = function ($spec) use ($classesRaw, $subjects) {
                $subjectName = $subjects->firstWhere('id', $spec['subject_id'])?->name ?? 'Unknown';

                // Map specific_grades (name) to class IDs
                $allowedClasses = [];
                if (empty($spec['specific_grades'])) {
                    // All classes if none specified
                    $allowedClasses = $classesRaw->pluck('id')->toArray();
                } else {
                    // Filter classes that belong to the specified grade name
                    $allowedClasses = $classesRaw->filter(function ($c) use ($spec) {
                        return $c->grade->name === $spec['specific_grades'];
                    })->pluck('id')->toArray();
                }

                return [
                    'subject' => $subjectName,
                    'allowedClasses' => $allowedClasses,
                ];
            };

            $primarySpecs = collect($specializations)->filter(fn($s) => ($s['type'] ?? '') === 'Primary Subject');
            $secondarySpecs = collect($specializations)->filter(fn($s) => ($s['type'] ?? '') !== 'Primary Subject');

            return [
                'id' => $teacher->id,
                'name' => $teacher->name,
                'workload' => [
                    'min' => $config?->min_classes_per_day ?? 0,
                    'max' => $config?->max_classes_per_day ?? 8,
                ],
                'subjects' => [
                    'primary' => $primarySpecs->map($mapSpec)->values()->toArray(),
                    'secondary' => $secondarySpecs->map($mapSpec)->values()->toArray(),
                ]
            ];
        });

        return [
            'teachers' => $teachers,
            'subjects' => $subjects,
            'classes' => $classes,
        ];
    }
}
