<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\ExamTerm;
use App\Models\StudentExamMark;
use App\Services\PromotionService;
use Illuminate\Support\Facades\DB;

class AcademicPromotionController extends Controller
{
    protected $promotionService;

    public function __construct(PromotionService $promotionService)
    {
        $this->promotionService = $promotionService;
    }

    public function getPromotionRoster(Request $request)
    {
        $validated = $request->validate([
            'school_class_id' => 'required|exists:school_classes,id',
            'session_id' => 'required|exists:sessions,id',
        ]);

        $class = SchoolClass::findOrFail($validated['school_class_id']);
        
        $students = Student::whereHas('academicRecords', function($q) use ($validated) {
            $q->where('school_class_id', $validated['school_class_id'])
              ->where('academic_year', $validated['session_id'])
              ->where('status', 'active');
        })
        ->with(['examMarks' => function($q) use ($validated) {
            $q->whereHas('structure', function($sq) use ($validated) {
                $sq->whereHas('term', function($tq) use ($validated) {
                    $tq->where('session_id', $validated['session_id']);
                });
            });
        }])
        ->get();

        // Simple Pass/Fail Logic: Passed if failed 0 subjects in that session
        $roster = $students->map(function($student) {
            $failedCount = $student->examMarks->filter(function($mark) {
                return $mark->total_obtained < $mark->structure->passing_marks;
            })->count();

            return [
                'id' => $student->id,
                'name' => $student->name,
                'admission_number' => $student->admission_number,
                'is_passed' => $failedCount === 0,
                'failed_subjects_count' => $failedCount
            ];
        });

        return response()->json(['success' => true, 'data' => $roster]);
    }

    public function promote(Request $request)
    {
        $validated = $request->validate([
            'student_ids' => 'required|array',
            'student_ids.*' => 'exists:students,id',
            'target_class_id' => 'required|exists:school_classes,id',
            'target_session_id' => 'required|exists:sessions,id',
            'type' => 'required|in:promote,repeat'
        ]);

        if ($validated['type'] === 'promote') {
            $count = $this->promotionService->promoteStudents(
                $validated['student_ids'],
                $validated['target_class_id'],
                $validated['target_session_id']
            );
        } else {
            $count = $this->promotionService->repeatStudents(
                $validated['student_ids'],
                $validated['target_class_id'], // current class
                $validated['target_session_id']
            );
        }

        return response()->json(['success' => true, 'message' => "$count students processed successfully."]);
    }
}
