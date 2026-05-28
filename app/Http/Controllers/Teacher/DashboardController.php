<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\TimetableEntry;
use App\Models\StudentAttendance;
use App\Models\StudentAcademicRecord;
use App\Models\Student;
use Illuminate\Http\Request;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $today = Carbon::today();
        $dayOfWeek = $today->format('l');

        // 1. Managed Classes
        $managedClassIds = SchoolClass::where('class_teacher_id', $user->id)->pluck('id');
        
        // 2. Stats
        $totalStudents = StudentAcademicRecord::whereIn('school_class_id', $managedClassIds)
            ->where('academic_year', $user->school->current_session ?? '2024-25')
            ->count();
        
        // Attendance for managed classes today
        $attendanceCount = StudentAttendance::whereIn('school_class_id', $managedClassIds)
            ->where('date', $today->toDateString())
            ->where('status', 'present')
            ->count();
            
        $attendancePercentage = $totalStudents > 0 ? round(($attendanceCount / $totalStudents) * 100) : 0;

        // 3. Today's Schedule
        $schedule = TimetableEntry::with(['subject:id,name', 'schoolClass:id,grade_id,section_id', 'schoolClass.grade:id,name', 'schoolClass.section:id,name', 'classroom:id,name'])
            ->where('user_id', $user->id)
            ->where('day_of_week', $dayOfWeek)
            ->where('is_active', true)
            ->orderBy('start_time')
            ->get()
            ->map(function($entry) {
                return [
                    'time' => Carbon::parse($entry->start_time)->format('H:i'),
                    'duration' => Carbon::parse($entry->start_time)->diffInMinutes(Carbon::parse($entry->end_time)) . 'm',
                    'subject' => $entry->subject->name,
                    'room' => $entry->classroom ? $entry->classroom->name : 'Classroom',
                    'class' => "Grade " . ($entry->schoolClass->grade->name ?? '') . "-" . ($entry->schoolClass->section->name ?? ''),
                    'color' => $this->getSubjectColor($entry->subject->name)
                ];
            });

        // 4. Own Attendance
        $ownAttendance = \App\Models\Attendance::where('user_id', $user->id)
            ->where('date', $today->toDateString())
            ->first();

        return $this->successResponse([
            'stats' => [
                ['label' => 'STUDENTS', 'value' => (string)$totalStudents, 'color' => '#5a5ce5'],
                ['label' => 'ATTENDANCE', 'value' => $attendancePercentage . '%', 'color' => '#48c78e'],
                ['label' => 'PENDING', 'value' => '0', 'color' => '#ff9f43'],
            ],
            'schedule' => $schedule,
            'own_attendance' => $ownAttendance
        ]);
    }

    private function getSubjectColor($name)
    {
        $colors = ['#5a5ce5', '#48c78e', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4', '#10b981'];
        return $colors[abs(crc32($name)) % count($colors)];
    }
}
