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
use Illuminate\Validation\Rule;

class AcademicPromotionController extends Controller
{
    protected $promotionService;

    public function __construct(PromotionService $promotionService)
    {
        $this->promotionService = $promotionService;
    }

    private function scoped(string $table, int $schoolId, string $col = 'school_id'): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists($table, 'id')->where(fn($q) => $q->where($col, $schoolId));
    }

    public function getPromotionRoster(Request $request)
    {
        $schoolId = $request->user()->school_id;
        $validated = $request->validate([
            'school_class_id' => ['required', $this->scoped('school_classes', $schoolId)],
            'session_id'      => ['required', $this->scoped('sessions', $schoolId)],
        ]);

        $class = SchoolClass::where('school_id', $schoolId)
            ->findOrFail($validated['school_class_id']);

        // N+1 fix: eager-load `structure` on the examMarks relation so the
        // `$mark->structure->passing_marks` access in the map() below doesn't
        // fire a query per mark row (was ~720 queries per class).
        $students = Student::where('school_id', $schoolId)
            ->whereHas('academicRecords', function($q) use ($validated) {
                $q->where('school_class_id', $validated['school_class_id'])
                  ->where('academic_year', $validated['session_id'])
                  ->where('status', 'active');
            })
            ->with(['examMarks' => function($q) use ($validated) {
                $q->whereHas('structure', function($sq) use ($validated) {
                    $sq->whereHas('term', function($tq) use ($validated) {
                        $tq->where('session_id', $validated['session_id']);
                    });
                })->with('structure:id,passing_marks');
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
        $schoolId = $request->user()->school_id;
        $validated = $request->validate([
            'student_ids'        => 'required|array',
            'student_ids.*'      => ['required', $this->scoped('students', $schoolId)],
            'target_class_id'    => ['required', $this->scoped('school_classes', $schoolId)],
            'target_session_id'  => ['required', $this->scoped('sessions', $schoolId)],
            // The source class/session matters: PromotionService needs them to
            // scope the academic_records update to "this class, this session"
            // instead of touching every record the student has across history.
            'source_class_id'    => ['nullable', $this->scoped('school_classes', $schoolId)],
            'source_session_id'  => ['nullable', $this->scoped('sessions', $schoolId)],
            'type'               => 'required|in:promote,repeat'
        ]);

        if ($validated['type'] === 'promote') {
            $count = $this->promotionService->promoteStudents(
                $validated['student_ids'],
                $validated['target_class_id'],
                $validated['target_session_id'],
                $schoolId,
                $validated['source_class_id'] ?? null,
                $validated['source_session_id'] ?? null
            );
        } else {
            $count = $this->promotionService->repeatStudents(
                $validated['student_ids'],
                $validated['target_class_id'], // current class
                $validated['target_session_id'],
                $schoolId,
                $validated['source_class_id'] ?? null,
                $validated['source_session_id'] ?? null
            );
        }

        return response()->json(['success' => true, 'message' => "$count students processed successfully."]);
    }
}
