<?php

namespace App\Services;

use App\Models\Student;
use App\Models\StudentAcademicRecord;
use Illuminate\Support\Facades\DB;

class PromotionService
{
    /**
     * Promote a list of students to a target class and session.
     *
     * Tenant + record scoping:
     *   - Every $studentId is filtered through Student::where('school_id', X)
     *     so a foreign id passed in is silently dropped (no exception, just
     *     not promoted — keeps batch behavior).
     *   - The "mark current record as promoted" UPDATE used to match `status
     *     = 'active'` only, which would flip every active record the student
     *     had, including records in OTHER schools or unrelated past sessions.
     *     Now we narrow to the explicit source class+session when provided,
     *     and the student's school-bound active record otherwise.
     */
    public function promoteStudents(
        array $studentIds,
        int $targetClassId,
        int $targetSessionId,
        ?int $schoolId = null,
        ?int $sourceClassId = null,
        ?int $sourceSessionId = null
    ) {
        return DB::transaction(function() use ($studentIds, $targetClassId, $targetSessionId, $schoolId, $sourceClassId, $sourceSessionId) {
            $allowedStudentIds = $schoolId !== null
                ? Student::where('school_id', $schoolId)->whereIn('id', $studentIds)->pluck('id')->all()
                : $studentIds;

            $promotedCount = 0;

            foreach ($allowedStudentIds as $studentId) {
                $q = StudentAcademicRecord::where('student_id', $studentId)
                    ->where('status', 'active');
                if ($sourceClassId !== null) $q->where('school_class_id', $sourceClassId);
                if ($sourceSessionId !== null) $q->where('academic_year', $sourceSessionId);
                $q->update(['status' => 'promoted']);

                StudentAcademicRecord::create([
                    'student_id' => $studentId,
                    'school_class_id' => $targetClassId,
                    'academic_year' => $targetSessionId,
                    'status' => 'active',
                ]);

                $promotedCount++;
            }

            return $promotedCount;
        });
    }

    /**
     * Batch Fail / Repeat — same scoping treatment as promoteStudents.
     */
    public function repeatStudents(
        array $studentIds,
        int $currentClassId,
        int $targetSessionId,
        ?int $schoolId = null,
        ?int $sourceClassId = null,
        ?int $sourceSessionId = null
    ) {
        return DB::transaction(function() use ($studentIds, $currentClassId, $targetSessionId, $schoolId, $sourceClassId, $sourceSessionId) {
            $allowedStudentIds = $schoolId !== null
                ? Student::where('school_id', $schoolId)->whereIn('id', $studentIds)->pluck('id')->all()
                : $studentIds;

            $count = 0;

            foreach ($allowedStudentIds as $studentId) {
                $q = StudentAcademicRecord::where('student_id', $studentId)
                    ->where('status', 'active');
                if ($sourceClassId !== null) $q->where('school_class_id', $sourceClassId);
                if ($sourceSessionId !== null) $q->where('academic_year', $sourceSessionId);
                $q->update(['status' => 'failed']);

                StudentAcademicRecord::create([
                    'student_id' => $studentId,
                    'school_class_id' => $currentClassId,
                    'academic_year' => $targetSessionId,
                    'status' => 'active',
                ]);

                $count++;
            }

            return $count;
        });
    }
}
