<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentAttendance;
use App\Models\StudentAcademicRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class StudentAttendanceController extends Controller
{
    /**
     * Get classes authorized for the current teacher/admin
     */
    public function getClasses(Request $request)
    {
        $user = Auth::user();
        $schoolId = $user->school_id;

        $query = SchoolClass::where('school_id', $schoolId)
            ->with(['grade', 'section']);

        if (!$user->isAdmin()) {
            // Logic for Wildcard/Assigned Teachers 
            // 1. Check if user is a Class Teacher
            // 2. Check if user is in Timetable
            // 3. Check for specific Permission Overrides (attendance.manage_all)
            
            $hasWildcard = $user->canAccess('attendance', 'manage_all');

            if (!$hasWildcard) {
                $query->where(function($q) use ($user) {
                    $q->where('class_teacher_id', $user->id)
                      ->orWhereHas('timetableEntries', function($sub) use ($user) {
                          $sub->where('user_id', $user->id);
                      });
                });
            }
        }

        $classes = $query->get()->map(function($class) {
            return [
                'id' => $class->id,
                'name' => $class->grade->name . ' - ' . $class->section->name,
                'is_class_teacher' => $class->class_teacher_id === Auth::id()
            ];
        });

        return response()->json($classes);
    }

    /**
     * Get students for a class and check existing attendance
     */
    public function getStudentList(Request $request)
    {
        $request->validate([
            'school_class_id' => 'required|exists:school_classes,id',
            'date' => 'required|date'
        ]);

        $user = Auth::user();
        $classId = $request->school_class_id;
        $date = $request->date;
        $schoolClass = SchoolClass::findOrFail($classId);

        // Security Check
        $this->authorizeAccess($user, $schoolClass);

        // Check for existing records
        $existingRecords = StudentAttendance::where('school_class_id', $classId)
            ->where('date', $date)
            ->with('teacher:id,name')
            ->get();

        $markedBy = null;
        if ($existingRecords->isNotEmpty()) {
            $record = $existingRecords->first();
            $markedBy = $record->teacher;

            // Exclusive Visibility Logic:
            // "if a class attendance done than only who mark attendance and admin can see or update"
            // "class teacher always view ... but teacher who mark ... and admin can view that day attendance"
            $canView = $user->isAdmin() || 
                      $schoolClass->class_teacher_id === $user->id || 
                      $record->teacher_id === $user->id;

            if (!$canView) {
                return response()->json([
                    'message' => 'Attendance already marked by ' . $record->teacher->name . '. You do not have permission to view these records.',
                    'already_marked' => true,
                    'marked_by' => $record->teacher->name
                ], 403);
            }
        }

        // Fetch students via Academic Records
        $students = StudentAcademicRecord::where('school_class_id', $classId)
            ->where('status', 'active')
            ->with('student:id,name,photo_path')
            ->get()
            ->map(function($record) use ($existingRecords) {
                // Attach existing status if any
                $attendance = $existingRecords->where('student_id', $record->student_id)->first();
                return [
                    'student_id' => $record->student_id,
                    'roll_number' => $record->roll_number,
                    'name' => $record->student->name,
                    'status' => $attendance ? $attendance->status : 'present', // Default to present
                    'remarks' => $attendance ? $attendance->remarks : '',
                    'id' => $attendance ? $attendance->id : null
                ];
            });

        return response()->json([
            'students' => $students,
            'is_historical' => $date !== Carbon::today()->toDateString(),
            'marked_by_name' => $markedBy ? $markedBy->name : null,
            'can_update' => $user->isAdmin() || $schoolClass->class_teacher_id === $user->id || ($markedBy && $markedBy->id === $user->id)
        ]);
    }

    /**
     * Submit attendance (Bulk)
     */
    public function submit(Request $request)
    {
        $request->validate([
            'school_class_id' => 'required|exists:school_classes,id',
            'date' => 'required|date',
            'attendance' => 'required|array',
            'attendance.*.student_id' => 'required|exists:students,id',
            'attendance.*.status' => 'required|in:present,absent,late,half_day',
        ]);

        $user = Auth::user();
        $date = $request->date;
        $classId = $request->school_class_id;
        $schoolClass = SchoolClass::findOrFail($classId);

        $isPastDate = Carbon::parse($date)->lt(Carbon::today());

        // Security Check
        $this->authorizeAccess($user, $schoolClass);

        // Past-Date Enforcements:
        // 1. Only Class Teacher or Admin can modify past records
        // 2. Remarks are mandatory for past modifications
        if ($isPastDate) {
            $canModifyPast = $user->isAdmin() || $schoolClass->class_teacher_id === $user->id;
            if (!$canModifyPast) {
                return response()->json(['message' => 'Attendance for previous dates can only be modified by the Class Teacher or an Administrator.'], 403);
            }

            // Validate that all records in the array have remarks if the date is in the past
            foreach ($request->attendance as $data) {
                if (empty($data['remarks'])) {
                    return response()->json(['message' => 'A reason for modification is required in the remarks field for all historical records.'], 422);
                }
            }
        }

        // Check if updating existing record (legacy logic kept for permissions, but superseded by past-date check above)
        $existing = StudentAttendance::where('school_class_id', $classId)
            ->where('date', $date)
            ->first();

        if ($existing && !$isPastDate) {
            // Normal (Today) Update Auth: Only Admin, Class Teacher, or Original Marker can update
            $canUpdate = $user->isAdmin() || 
                        $schoolClass->class_teacher_id === $user->id || 
                        $existing->teacher_id === $user->id;

            if (!$canUpdate) {
                return response()->json(['message' => 'Only the original marker, class teacher, or admin can update this record.'], 403);
            }
        }

        foreach ($request->attendance as $data) {
            StudentAttendance::updateOrCreate(
                [
                    'student_id' => $data['student_id'],
                    'date' => $date,
                ],
                [
                    'school_id' => $user->school_id,
                    'school_class_id' => $classId,
                    'teacher_id' => $user->id,
                    'status' => $data['status'],
                    'remarks' => $data['remarks'] ?? null,
                ]
            );
        }

        return response()->json(['message' => 'Attendance successfully recorded.']);
    }

    /**
     * Get attendance history for a class (Monthly Grid)
     */
    public function getHistory(Request $request)
    {
        $request->validate([
            'school_class_id' => 'required|exists:school_classes,id',
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer|min:2020'
        ]);

        $user = Auth::user();
        $classId = $request->school_class_id;
        $month = $request->month;
        $year = $request->year;

        $schoolClass = SchoolClass::findOrFail($classId);
        $this->authorizeAccess($user, $schoolClass);

        // Security: Wildcard teachers cannot view history unless they are the class teacher or admin
        if (!$user->isAdmin() && $schoolClass->class_teacher_id !== $user->id) {
            return response()->json(['message' => 'Attendance history is restricted to Class Teachers and Admins.'], 403);
        }

        $records = StudentAttendance::where('school_class_id', $classId)
            ->whereMonth('date', $month)
            ->whereYear('date', $year)
            ->with(['student:id,name', 'teacher:id,name'])
            ->get();

        return response()->json($records);
    }

    /**
     * Get attendance history for a specific student
     */
    public function getStudentHistory(Request $request)
    {
        $request->validate([
            'student_id' => 'required|exists:students,id',
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer|min:2020'
        ]);

        $user = Auth::user();
        $studentId = $request->student_id;
        $month = $request->month;
        $year = $request->year;

        // Find the student's class to check authorization
        $academicRecord = StudentAcademicRecord::where('student_id', $studentId)
            ->where('status', 'active')
            ->firstOrFail();
        
        $this->authorizeAccess($user, $academicRecord->schoolClass);

        $records = StudentAttendance::where('student_id', $studentId)
            ->whereMonth('date', $month)
            ->whereYear('date', $year)
            ->orderBy('date', 'asc')
            ->get();

        return response()->json($records);
    }

    /**
     * Get comprehensive attendance report for a student (Dashboard View)
     */
    public function getStudentReport(Request $request)
    {
        $request->validate([
            'student_id' => 'required|exists:students,id',
        ]);

        $user = Auth::user();
        $studentId = $request->student_id;

        $academicRecord = StudentAcademicRecord::where('student_id', $studentId)
            ->where('status', 'active')
            ->firstOrFail();
        
        $this->authorizeAccess($user, $academicRecord->schoolClass);

        return response()->json($this->generateReportData($studentId, $user->school_id));
    }

    /**
     * Get personal attendance report for the logged-in student (App View)
     */
    public function getPersonalReport(Request $request)
    {
        $user = Auth::user(); // This will be a StudentLogin instance
        
        if (!$user->student_id) {
            return response()->json(['message' => 'Student record link not found.'], 404);
        }

        return response()->json($this->generateReportData($user->student_id, $user->student->school_id));
    }

    /**
     * Shared logic to calculate counts and percentage
     */
    private function generateReportData($studentId, $schoolId)
    {
        $records = StudentAttendance::where('student_id', $studentId)
            ->where('school_id', $schoolId)
            ->orderBy('date', 'desc')
            ->get();

        $totalDays = $records->count();
        
        $counts = [
            'present' => $records->where('status', 'present')->count(),
            'absent' => $records->where('status', 'absent')->count(),
            'late' => $records->where('status', 'late')->count(),
            'half_day' => $records->where('status', 'half_day')->count(),
        ];

        // Calculation: (Present + Late + 0.5 * HalfDay) / Total
        $weight = $counts['present'] + $counts['late'] + ($counts['half_day'] * 0.5);
        $percentage = $totalDays > 0 ? round(($weight / $totalDays) * 100, 2) : 0;

        return [
            'summary' => array_merge($counts, [
                'total_days' => $totalDays,
                'percentage' => $percentage
            ]),
            'records' => $records
        ];
    }

    /**
     * Helper to authorize initial access to a class
     */
    private function authorizeAccess($user, $class)
    {
        if ($user->isAdmin()) return true;

        $isClassTeacher = $class->class_teacher_id === $user->id;
        $hasWildcard = $user->canAccess('attendance', 'manage_all');
        
        $isAssigned = false;
        if (!$isClassTeacher && !$hasWildcard) {
            $isAssigned = $class->timetableEntries()->where('user_id', $user->id)->exists();
        }

        if (!$isClassTeacher && !$hasWildcard && !$isAssigned) {
            abort(403, 'You are not authorized to manage attendance for this class.');
        }
    }
}
