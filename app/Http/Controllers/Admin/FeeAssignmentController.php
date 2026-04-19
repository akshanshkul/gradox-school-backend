<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FeeAssignment;
use App\Models\FeeType;
use App\Models\Student;
use App\Models\SchoolClass;
use Illuminate\Support\Facades\DB;

class FeeAssignmentController extends Controller
{
    public function index(Request $request)
    {
        $schoolId = $request->user()->school_id;
        $assignments = FeeAssignment::with(['feeType', 'student', 'schoolClass.grade', 'schoolClass.section', 'grade'])
            ->where('school_id', $schoolId)
            ->paginate(20);
            
        return response()->json(['success' => true, 'data' => $assignments]);
    }

    public function store(Request $request)
    {
        $schoolId = $request->user()->school_id;
        
        $school = $request->user()->school;
        $activeSession = $school->getActiveSession();

        $validated = $request->validate([
            'fee_type_id' => 'required|exists:fee_types,id',
            'amount' => 'required|numeric|min:0',
            'target_type' => 'required|in:grade,class,student',
            'grade_id' => 'required_if:target_type,grade|nullable|exists:grades,id',
            'class_ids' => 'required_if:target_type,class|nullable|array',
            'class_ids.*' => 'exists:school_classes,id',
            'student_id' => 'required_if:target_type,student|nullable|exists:students,id',
            'due_day' => 'nullable|integer|min:1|max:31',
            'due_date' => 'nullable|date',
        ]);

        $baseData = [
            'school_id' => $schoolId,
            'fee_type_id' => $validated['fee_type_id'],
            'session_id' => $activeSession->id,
            'amount' => $validated['amount'],
            'due_day' => $validated['due_day'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
        ];

        return DB::transaction(function () use ($validated, $baseData, $schoolId, $activeSession) {
            if ($validated['target_type'] === 'grade') {
                FeeAssignment::firstOrCreate(
                    array_merge($baseData, ['grade_id' => $validated['grade_id']])
                );
            } elseif ($validated['target_type'] === 'student') {
                FeeAssignment::firstOrCreate(
                    array_merge($baseData, ['student_id' => $validated['student_id']])
                );
            } elseif ($validated['target_type'] === 'class') {
                foreach ($validated['class_ids'] as $classId) {
                    FeeAssignment::firstOrCreate(
                        array_merge($baseData, ['class_id' => $classId])
                    );
                }
            }

            return response()->json(['success' => true, 'message' => 'Fees mapped and synchronized successfully']);
        });
    }
}
