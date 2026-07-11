<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FeeAssignment;
use App\Models\FeeType;
use App\Models\Student;
use App\Models\SchoolClass;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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

        // All FKs are now school-scoped: an admin in school A cannot point a
        // fee assignment at school B's grade / class / student / fee_type.
        // grade_ids[] is the new multi-grade shape (preferred). grade_id
        // remains accepted for back-compat with any caller still on the old
        // single-grade payload — it's just normalized into grade_ids below.
        $validated = $request->validate([
            'fee_type_id' => [
                'required',
                Rule::exists('fee_types', 'id')->where(fn($q) => $q->where('school_id', $schoolId)),
            ],
            'amount' => 'required|numeric|min:0',
            'target_type' => 'required|in:grade,class,student',

            // Multi-grade (new):
            'grade_ids' => 'nullable|array',
            'grade_ids.*' => [
                Rule::exists('grades', 'id')->where(fn($q) => $q->where('school_id', $schoolId)),
            ],
            // Single-grade (legacy):
            'grade_id' => [
                'nullable',
                Rule::exists('grades', 'id')->where(fn($q) => $q->where('school_id', $schoolId)),
            ],

            'class_ids' => 'required_if:target_type,class|nullable|array',
            'class_ids.*' => [
                Rule::exists('school_classes', 'id')->where(fn($q) => $q->where('school_id', $schoolId)),
            ],

            'student_id' => [
                'required_if:target_type,student',
                'nullable',
                Rule::exists('students', 'id')->where(fn($q) => $q->where('school_id', $schoolId)),
            ],

            'due_day' => 'nullable|integer|min:1|max:31',
            'due_date' => 'nullable|date',
        ]);

        // Normalize grade payload. After this block, $gradeIds is either a
        // non-empty array (when target_type === 'grade') or [].
        $gradeIds = [];
        if (($validated['target_type'] ?? null) === 'grade') {
            if (!empty($validated['grade_ids'])) {
                $gradeIds = array_values(array_unique($validated['grade_ids']));
            } elseif (!empty($validated['grade_id'])) {
                $gradeIds = [$validated['grade_id']];
            }
            if (empty($gradeIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pick at least one grade.',
                ], 422);
            }
        }

        $baseData = [
            'school_id' => $schoolId,
            'fee_type_id' => $validated['fee_type_id'],
            'session_id' => $activeSession->id,
            'amount' => $validated['amount'],
            'due_day' => $validated['due_day'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
        ];

        return DB::transaction(function () use ($validated, $baseData, $gradeIds) {
            $created = 0;
            $skipped = 0;

            if ($validated['target_type'] === 'grade') {
                foreach ($gradeIds as $gid) {
                    $fa = FeeAssignment::firstOrCreate(
                        array_merge($baseData, ['grade_id' => $gid])
                    );
                    $fa->wasRecentlyCreated ? $created++ : $skipped++;
                }
            } elseif ($validated['target_type'] === 'student') {
                $fa = FeeAssignment::firstOrCreate(
                    array_merge($baseData, ['student_id' => $validated['student_id']])
                );
                $fa->wasRecentlyCreated ? $created++ : $skipped++;
            } elseif ($validated['target_type'] === 'class') {
                foreach ($validated['class_ids'] as $classId) {
                    $fa = FeeAssignment::firstOrCreate(
                        array_merge($baseData, ['class_id' => $classId])
                    );
                    $fa->wasRecentlyCreated ? $created++ : $skipped++;
                }
            }

            $message = $created > 0
                ? "Fees mapped: {$created} new" . ($skipped > 0 ? ", {$skipped} already existed" : '')
                : 'All selected targets already had this fee assignment.';

            return response()->json([
                'success' => true,
                'message' => $message,
                'created' => $created,
                'skipped' => $skipped,
            ]);
        });
    }
}
