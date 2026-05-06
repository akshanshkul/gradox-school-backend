<?php

namespace App\Services;

use App\Models\StudentAcademicRecord;
use Illuminate\Support\Facades\DB;

class PromotionService
{
    /**
     * Promote a list of students to a target class and session.
     */
    public function promoteStudents($studentIds, $targetClassId, $targetSessionId)
    {
        return DB::transaction(function() use ($studentIds, $targetClassId, $targetSessionId) {
            $promotedCount = 0;
            
            foreach ($studentIds as $studentId) {
                // 1. Mark current record as 'promoted'
                StudentAcademicRecord::where('student_id', $studentId)
                    ->where('status', 'active')
                    ->update(['status' => 'promoted']);

                // 2. Create new record for target session
                StudentAcademicRecord::create([
                    'student_id' => $studentId,
                    'school_class_id' => $targetClassId,
                    'academic_year' => $targetSessionId,
                    'status' => 'active'
                    // roll_number can be reset or copied later via secondary API
                ]);
                
                $promotedCount++;
            }
            
            return $promotedCount;
        });
    }

    /**
     * Batch Fail / Repeat
     */
    public function repeatStudents($studentIds, $currentClassId, $targetSessionId)
    {
        return DB::transaction(function() use ($studentIds, $currentClassId, $targetSessionId) {
            foreach ($studentIds as $studentId) {
                StudentAcademicRecord::where('student_id', $studentId)
                    ->where('status', 'active')
                    ->update(['status' => 'failed']);

                StudentAcademicRecord::create([
                    'student_id' => $studentId,
                    'school_class_id' => $currentClassId,
                    'academic_year' => $targetSessionId,
                    'status' => 'active'
                ]);
            }
        });
    }
}
