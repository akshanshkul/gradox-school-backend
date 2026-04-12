<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Subject;
use App\Models\SchoolClass;
use App\Models\RoleWorkloadConfig;
use App\Models\SchoolPeriod;
use App\Models\TimetableEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Services\MasterScheduler;

class TimetableSchedulingController extends Controller
{
    public function getTimetableSchedulingData(Request $request)
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->hasPermission('timetable_manage')) {
            return $this->errorResponse('Unauthorized access to timetable generator.', 403);
        }

        $school = $user->school;
        $periods = SchoolPeriod::where('school_id', $school->id)
            ->where('type', 'class')
            ->orderBy('sort_order')
            ->get(['id', 'name', 'start_time', 'end_time']);

        $periodsPerDay = $periods->count();

        // 1. Map School Info
        $dayMapping = [
            'Monday' => 'Mon', 'Tuesday' => 'Tue', 'Wednesday' => 'Wed',
            'Thursday' => 'Thu', 'Friday' => 'Fri', 'Saturday' => 'Sat', 'Sunday' => 'Sun'
        ];
        $abbreviatedDays = collect($school->working_days ?? ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'])
            ->map(fn($day) => $dayMapping[$day] ?? substr($day, 0, 3))
            ->values()
            ->toArray();

        // 2. Map Subjects
        $subjectsRaw = $school->subjects()->get(['id', 'name']);
        $subjects = $subjectsRaw->map(fn($s) => [
            'id' => $s->id,
            'name' => $s->name
        ])->values()->toArray();

        // 3. Map Classes & Build Subject Needs
        $classesRaw = $school->classes()
            ->with(['grade', 'section', 'subjects'])
            ->get();

        $subjectNeeds = [];
        $classes = $classesRaw->map(function ($class) use (&$subjectNeeds, $periodsPerDay) {
            $classId = $class->id;
            $classSubjects = [];
            $classPeriodsPerDay = $class->periods_per_day ?? $periodsPerDay;

            foreach ($class->subjects as $subject) {
                $subId = $subject->id;
                $classSubjects[] = $subId;
                $subjectNeeds[$classId][$subId] = $subject->pivot->periods_per_week ?? 1;
            }

            return [
                'id' => $classId,
                'name' => $class->grade->name . ($class->section ? " " . $class->section->name : ""),
                'grade_id' => $class->grade_id,
                'section_id' => $class->section_id,
                'default_classroom_id' => $class->default_classroom_id,
                'subjects' => $classSubjects,
                'classTeacher' => $class->class_teacher_id,
                'periodsPerDay' => $classPeriodsPerDay
            ];
        })->values()->toArray();

        // 4. Map Teachers & Eligibility
        $teachersRaw = $school->users()
            ->where('is_teaching', true)
            ->where('status', 'active')
            ->get();

        $roleConfigs = RoleWorkloadConfig::where('school_id', $school->id)
            ->get()
            ->keyBy('role_name');

        $teacherEligibility = [];
        $teachers = $teachersRaw->map(function ($teacher) use ($roleConfigs, $classesRaw, &$teacherEligibility) {
            $teacherId = $teacher->id;
            $details = $teacher->teacher_details ?? [];
            $specializations = $details['specializations'] ?? [];
            
            $primary = [];
            $secondary = [];

            foreach ($specializations as $spec) {
                $subId = $spec['subject_id'];
                if (($spec['type'] ?? '') === 'Primary Subject') {
                    $primary[] = $subId;
                } else {
                    $secondary[] = $subId;
                }

                // Calculate eligibility for this specific subject/teacher combo
                $targetGradeName = $spec['specific_grades'] ?? null;
                $matchingClassIds = $classesRaw->filter(function ($c) use ($targetGradeName) {
                    if (!$targetGradeName) return true; // All classes if no specific grade
                    return $c->grade->name === $targetGradeName;
                })->pluck('id')->values()->toArray();

                if (!empty($matchingClassIds)) {
                    $teacherEligibility[] = [
                        'teacherId' => $teacherId,
                        'subjectId' => $subId,
                        'classes' => $matchingClassIds
                    ];
                }
            }

            // Extract free periods
            $off_periods = $details['off_periods'] ?? [];
            $freePeriods = collect($off_periods)->pluck('period_id')->unique()->values()->toArray();

            $config = $roleConfigs->get($teacher->role);

            return [
                'id' => $teacherId,
                'name' => $teacher->name,
                'primary' => $primary,
                'secondary' => $secondary,
                'maxPeriodsPerDay' => $config?->max_classes_per_day ?? 6,
                'freePeriods' => $freePeriods
            ];
        })->values()->toArray();

        return $this->successResponse([
            'school' => [
                'days' => $abbreviatedDays,
                'periodsPerDay' => $periodsPerDay
            ],
            'subjects' => $subjects,
            'classes' => $classes,
            'subjectNeeds' => $subjectNeeds,
            'teachers' => $teachers,
            'teacherEligibility' => $teacherEligibility,
            'periods' => $periods,
        ], 'Institutional scheduling data retrieved successfully');
    }

    public function batchSyncTimetable(Request $request)
    {
        set_time_limit(0); // Allow long-running operations for large schools
        ini_set('memory_limit', '512M');
        
        $school = $request->user()->school;
        $startDate = $request->input('start_date');

        $scheduler = new MasterScheduler($school);
        $results = $scheduler->syncAllClasses($startDate);

        return $this->successResponse([
            'results' => $results,
        ], 'School-wide synchronization completed successfully.');
    }

    public function saveGeneratedTimetable(Request $request)
    {
        $request->validate([
            'school_class_id' => 'required|exists:school_classes,id',
            'slots' => 'required|array',
            'week_dates' => 'required|array|min:6',
        ]);

        $school_id = $request->user()->school_id;
        $class_id = $request->school_class_id;
        $slots = $request->slots;

        // 1. Fetch active teaching periods (Strictly 'class' type)
        $periods = SchoolPeriod::where('school_id', $school_id)
            ->where('type', 'class')
            ->orderBy('sort_order')
            ->get()
            ->values();

        // 2. Identify dates for current week (Align with UI logic starting Monday)
        $now = Carbon::now();
        $monday = $now->copy()->startOfWeek(Carbon::MONDAY);
        $dates = [];
        for ($i = 0; $i < 7; $i++) {
            $dates[] = $monday->copy()->addDays($i)->toDateString();
        }

        DB::transaction(function () use ($school_id, $class_id, $slots, $periods, $dates) {
            // 3. Delete existing entries for this class in the current week (Entire range)
            TimetableEntry::where('school_class_id', $class_id)
                ->whereBetween('date', [$dates[0], $dates[6]])
                ->delete();

            // 4. Batch Insert new entries
            $newEntries = [];
            foreach ($slots as $dIdx => $daySlots) {
                if ($dIdx >= count($dates)) break;
                $date = $dates[$dIdx];
                $dayOfWeek = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][$dIdx];

                if (!is_array($daySlots)) continue;

                foreach ($daySlots as $pIdx => $slot) {
                    if (!$slot) continue;
                    if ($pIdx >= $periods->count()) continue;

                    // Support for old data format: if IDs are missing, we can't save to DB
                    if (!isset($slot['subject_id']) || (!isset($slot['teacher_id']) && !isset($slot['user_id']))) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'slots' => ['One or more slots are in an incompatible format. Please regenerate the timetable for this class and try again.']
                        ]);
                    }

                    $period = $periods[$pIdx];
                    
                    $newEntries[] = [
                        'school_id' => $school_id,
                        'school_class_id' => $class_id,
                        'subject_id' => $slot['subject_id'],
                        'user_id' => $slot['teacher_id'] ?? $slot['user_id'],
                        'date' => $date,
                        'day_of_week' => $dayOfWeek,
                        'start_time' => $period->start_time,
                        'end_time' => $period->end_time,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
            
            if (count($newEntries) > 0) {
                TimetableEntry::insert($newEntries);
            }
        });

        return $this->successResponse(null, 'Timetable saved successfully!');
    }

    public function clearWeekTimetable(Request $request)
    {
        $school = $request->user()->school;
        
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        if (!$startDate || !$endDate) {
            // Determine target week dates (Monday to Sunday) - Fallback
            $now = Carbon::now();
            $monday = $now->copy()->startOfWeek(Carbon::MONDAY);
            $sunday = $monday->copy()->addDays(6);
            
            $startDate = $monday->toDateString();
            $endDate = $sunday->toDateString();
        }

        $count = TimetableEntry::where('school_id', $school->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->delete();

        return $this->successResponse(null, "Successfully cleared {$count} timetable entries from {$startDate} to {$endDate}.");
    }
}
