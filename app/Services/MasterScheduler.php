<?php

namespace App\Services;

use App\Models\SchoolClass;
use App\Models\SchoolPeriod;
use App\Models\TimetableEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MasterScheduler
{
    protected $school;
    protected $teacherWorkloads = [];
    protected $periods;
    protected $teacherSchedule = [];
    protected $teacherOffPeriods = [];

    public function __construct($school)
    {
        $this->school = $school;
        // Fetch only Class periods
        $this->periods = SchoolPeriod::where('school_id', $this->school->id)
            ->where('type', 'class')
            ->orderBy('start_time')
            ->get();
        
        // Performance Optimization: Cache all teacher workloads for the school
        $teachers = $this->school->users()->where('is_teaching', true)->get();
        foreach ($teachers as $t) {
            $this->teacherWorkloads[$t->id] = (int) ($t->workload['max'] ?? 8);
            $this->teacherOffPeriods[$t->id] = $t->teacher_details['off_periods'] ?? [];
        }
    }

    /**
     * Sycn and Auto-Generate Timetable for all classes in a specific week.
     */
    public function syncAllClasses($startDate = null)
    {
        $this->teacherSchedule = []; // Reset local cache for fresh week/multi-week runs
        $classes = $this->school->classes()->with(['subjects', 'grade', 'section'])->get();
        $results = [];

        // Determine target week dates (Monday to Saturday)
        $now = $startDate ? Carbon::parse($startDate) : Carbon::now();
        $monday = $now->copy()->startOfWeek(Carbon::MONDAY);
        $dates = [];
        for ($i = 0; $i < 6; $i++) {
            $dates[] = $monday->copy()->addDays($i)->toDateString();
        }

        DB::transaction(function () use ($classes, $dates, &$results) {
            // Pre-populate teacherSchedule with existing DB entries to avoid queries in loops
            $allEntries = TimetableEntry::where('school_id', $this->school->id)
                ->whereBetween('date', [$dates[0], $dates[5]])
                ->get(['user_id', 'date', 'start_time']);

            foreach ($allEntries as $ent) {
                if ($ent->user_id) {
                    $this->markTeacherBusy($ent->user_id, $ent->date, substr($ent->start_time, 0, 5));
                }
            }

            foreach ($classes as $class) {
                $status = $this->processClass($class, $dates);
                $results[] = [
                    'class_id' => $class->id,
                    'name' => "{$class->grade->name}-{$class->section->name}",
                    'status' => $status
                ];
            }
        });

        return $results;
    }

    protected function processClass(SchoolClass $class, array $dates)
    {
        // 1. Check current DB entries for this week
        $existingEntries = TimetableEntry::where('school_class_id', $class->id)
            ->whereBetween('date', [$dates[0], $dates[5]])
            ->count();

        $requiredLectures = $class->subjects->sum('pivot.periods_per_week') ?: 0;

        // 2. If counts match and already exists, we skip (Smart Clone/Keep)
        if ($existingEntries > 0 && $existingEntries == $requiredLectures) {
            return 'skipped_identical';
        }

        // 3. Clear existing entries to regenerate
        TimetableEntry::where('school_class_id', $class->id)
            ->whereBetween('date', [$dates[0], $dates[5]])
            ->delete();

        // 4. Run Generation Algorithm (Replicates frontend TimetableGenerator.tsx)
        return $this->generateForClass($class, $dates) ? 'regenerated' : 'failed';
    }

    protected function generateForClass($class, $dates)
    {
        $days = 6;
        $periodsCount = $this->periods->count();
        if ($periodsCount === 0) return false;

        $agenda = [];
        foreach ($class->subjects as $subject) {
            $lectures = $subject->pivot->periods_per_week ?: 1;
            $teacher = $this->findPotentialTeacher($subject->id, $class->id);
            
            for ($i = 0; $i < $lectures; $i++) {
                $agenda[] = [
                    'subject_id' => $subject->id,
                    'teacher_id' => $teacher ? $teacher->id : null
                ];
            }
        }

        // Shuffle agenda for variety
        shuffle($agenda);

        $slots = []; // [day][period]

        // STEP 1: Prioritize class teacher for Period 1
        if ($class->class_teacher_id) {
            foreach ($dates as $dIdx => $date) {
                // Find index of a subject handled by the class teacher
                $foundIndex = null;
                foreach ($agenda as $index => $item) {
                    if ($item['teacher_id'] == $class->class_teacher_id) {
                        if ($this->isTeacherFree($item['teacher_id'], $date, $this->periods[0]->start_time)) {
                            $foundIndex = $index;
                            break;
                        }
                    }
                }

                if ($foundIndex !== null && !isset($slots[$dIdx][0])) {
                    $item = $agenda[$foundIndex];
                    $this->markTeacherBusy($item['teacher_id'], $date, $this->periods[0]->start_time);
                    $slots[$dIdx][0] = $item;
                    array_splice($agenda, $foundIndex, 1);
                }
            }
        }

        // STEP 2: Fill remaining agenda using strict rules
        foreach ($agenda as $item) {
            $placed = false;
            // Strategy A: Ideal placement (one subject per day)
            for ($d = 0; $d < $days && !$placed; $d++) {
                $date = $dates[$d];
                for ($p = 0; $p < $periodsCount && !$placed; $p++) {
                    if (isset($slots[$d][$p])) continue;
                    
                    // Already have this subject today?
                    if ($this->isSubjectOnDay($slots[$d] ?? [], $item['subject_id'])) continue;
                    
                    if ($item['teacher_id'] && !$this->isTeacherFree($item['teacher_id'], $date, $this->periods[$p]->start_time)) continue;

                    $slots[$d][$p] = $item;
                    if ($item['teacher_id']) $this->markTeacherBusy($item['teacher_id'], $date, $this->periods[$p]->start_time);
                    $placed = true;
                }
            }

            // Strategy B: Fallback placement (ignore "already on day" if many lectures required)
            if (!$placed) {
                for ($d = 0; $d < $days && !$placed; $d++) {
                    $date = $dates[$d];
                    for ($p = 0; $p < $periodsCount && !$placed; $p++) {
                        if (!isset($slots[$d][$p])) {
                            if (!$item['teacher_id'] || $this->isTeacherFree($item['teacher_id'], $date, $this->periods[$p]->start_time)) {
                                $slots[$d][$p] = $item;
                                if ($item['teacher_id']) $this->markTeacherBusy($item['teacher_id'], $date, $this->periods[$p]->start_time);
                                $placed = true;
                            }
                        }
                    }
                }
            }
        }

        // 5. Batch Insert saved slots
        $newEntries = [];
        foreach ($slots as $dIdx => $daySlots) {
            foreach ($daySlots as $pIdx => $slot) {
                $period = $this->periods[$pIdx];
                $newEntries[] = [
                    'school_id' => $this->school->id,
                    'school_class_id' => $class->id,
                    'subject_id' => $slot['subject_id'],
                    'user_id' => $slot['teacher_id'],
                    'date' => $dates[$dIdx],
                    'day_of_week' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$dIdx],
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

        return true;
    }

    protected function findPotentialTeacher($subjectId, $classId)
    {
        // Simple search for qualified teachers based on metadata
        $teachers = $this->school->users()->where('is_teaching', true)->where('status', 'active')->get();
        
        foreach ($teachers as $teacher) {
            $details = $teacher->teacher_details ?? [];
            $specs = $details['specializations'] ?? [];
            foreach ($specs as $spec) {
                if ($spec['subject_id'] == $subjectId) return $teacher; // Found one
            }
        }
        return null;
    }

    protected function isTeacherFree($teacherId, $date, $startTime)
    {
        if (!$teacherId || !$date) return true;
        
        $timeStr = substr($startTime, 0, 5);
        $key = "{$teacherId}_{$date}_{$timeStr}";
        
        // 1. Specific Slot Check (Busy in another class/period at this time)
        if (isset($this->teacherSchedule[$key])) return false;

        // 2. Daily Capacity Check ($this->teacherSchedule pre-populated in syncAllClasses)
        $dailyCount = 0;
        foreach ($this->teacherSchedule as $k => $v) {
            if (str_starts_with($k, "{$teacherId}_{$date}_")) {
                $dailyCount++;
            }
        }

        // Fetch teacher max workload from cache
        $maxLoad = $this->teacherWorkloads[$teacherId] ?? 8;

        if ($dailyCount >= $maxLoad) return false;

        // 3. Off-period constraint check
        $offPeriods = $this->teacherOffPeriods[$teacherId] ?? [];
        if (count($offPeriods) > 0) {
            $dayName = Carbon::parse($date)->format('l'); // Monday, Tuesday...
            $currentPeriodId = null;
            foreach($this->periods as $p) {
                if (substr($p->start_time, 0, 5) === $timeStr) {
                    $currentPeriodId = $p->id;
                    break;
                }
            }

            if ($currentPeriodId) {
                foreach ($offPeriods as $op) {
                    if ($op['day'] === $dayName && $op['period_id'] == $currentPeriodId) {
                        return false; // Teacher is marked as OFF for this period
                    }
                }
            }
        }

        return true;
    }

    protected function markTeacherBusy($teacherId, $date, $startTime)
    {
        if (!$teacherId || !$date) return;
        $timeStr = substr($startTime, 0, 5);
        $key = "{$teacherId}_{$date}_{$timeStr}";
        $this->teacherSchedule[$key] = true;
    }

    protected function isSubjectOnDay(array $daySlots, $subjectId)
    {
        foreach ($daySlots as $slot) {
            if (isset($slot['subject_id']) && $slot['subject_id'] == $subjectId) return true;
        }
        return false;
    }
}
