<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SchoolClass;
use App\Models\ExamStructure;
use App\Models\StudentExamMark;
use App\Models\GradingScale;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

class MarkManagementController extends Controller
{
    public function getEntrySheet(Request $request)
    {
        $validated = $request->validate([
            'exam_term_id' => 'required|exists:exam_terms,id',
            'school_class_id' => 'required|exists:school_classes,id',
            'subject_id' => 'required|exists:subjects,id',
            'exam_type_id' => 'required|exists:exam_types,id',
        ]);

        $userId = $request->user()->id;
        $class = SchoolClass::findOrFail($validated['school_class_id']);
        
        // RBAC Check
        $isClassTeacher = ($class->class_teacher_id == $userId);
        $isSubjectTeacher = DB::table('class_subject')
            ->where('school_class_id', $validated['school_class_id'])
            ->where('subject_id', $validated['subject_id'])
            ->where('teacher_id', $userId)
            ->exists();

        $user = $request->user();
        $isPowerUser = $user->hasPermission('academic.update');

        if (!$isClassTeacher && !$isSubjectTeacher && !$isPowerUser) {
            return response()->json(['success' => false, 'message' => 'Unauthorized: You are not assigned to this class or subject.'], 403);
        }

        $structure = ExamStructure::where('exam_term_id', $validated['exam_term_id'])
            ->where('exam_type_id', $validated['exam_type_id'])
            ->where('school_class_id', $validated['school_class_id'])
            ->where('subject_id', $validated['subject_id'])
            ->with(['components', 'term.session'])
            ->first();

        if (!$structure) {
            return response()->json(['success' => false, 'message' => 'Exam structure not configured for this subject.'], 404);
        }

        if (!$structure->term || !$structure->term->session) {
            return response()->json(['success' => false, 'message' => 'Academic Term or Session configuration is missing.'], 400);
        }

        $sessionId = $structure->term->session->id;

        $students = Student::whereHas('academicRecords', function($q) use ($class, $sessionId) {
            $q->where('school_class_id', $class->id)
              ->where('academic_year', $sessionId);
        })->with([
            'academicRecords' => function($q) use ($class, $sessionId) {
                $q->where('school_class_id', $class->id)
                  ->where('academic_year', $sessionId);
            },
            'examMarks' => function($q) use ($structure) {
                $q->where('exam_structure_id', $structure->id);
            }
        ])->get();

        // Map the correct academic record to a 'current_record' property for the frontend
        $students->map(function($student) {
            $student->current_record = $student->academicRecords->first();
            return $student;
        });

        return response()->json([
            'success' => true, 
            'data' => [
                'structure' => $structure,
                'students' => $students,
                'is_published' => $structure->is_published,
                'can_publish' => ($isClassTeacher || $isPowerUser)
            ]
        ]);
    }

    public function submitMarks(Request $request)
    {
        $request->validate([
            'exam_structure_id' => 'required|exists:exam_structures,id',
            'marks' => 'required|array',
            'marks.*.student_id' => 'required|exists:students,id',
            'marks.*.component_marks' => 'nullable|array',
            'marks.*.grade_obtained' => 'nullable|string',
            'marks.*.attendance_status' => 'required|in:present,absent,sick,exempt',
            'marks.*.teacher_remarks' => 'nullable|string'
        ]);

        $structure = ExamStructure::with(['components', 'term'])->findOrFail($request->exam_structure_id);
        $userId = $request->user()->id;

        // RBAC Check
        $class = $structure->schoolClass;
        $isClassTeacher = ($class->class_teacher_id == $userId);
        $isSubjectTeacher = DB::table('class_subject')
            ->where('school_class_id', $class->id)
            ->where('subject_id', $structure->subject_id)
            ->where('teacher_id', $userId)
            ->exists();

        $user = $request->user();
        $isPowerUser = $user->hasPermission('academic.update');

        if (!$isClassTeacher && !$isSubjectTeacher && !$isPowerUser) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        if ($structure->is_published && !$isPowerUser) {
            return response()->json(['success' => false, 'message' => 'Marks are already published and cannot be modified.'], 403);
        }

        $gradings = GradingScale::where('school_id', $request->user()->school_id)->get();
        $totalMaxMarks = $structure->components->sum('max_marks');
        $scoringType = $structure->scoring_type;

        DB::transaction(function() use ($request, $structure, $gradings, $totalMaxMarks, $scoringType) {
            foreach ($request->marks as $markData) {
                if ($scoringType === 'grades') {
                    $obtained = 0;
                    $finalGrade = $markData['grade_obtained'] ?? null;
                } else {
                    $obtained = collect($markData['component_marks'] ?? [])->sum();
                    $percentage = $totalMaxMarks > 0 ? ($obtained / $totalMaxMarks) * 100 : 0;
                    
                    $grade = $gradings->where('min_percent', '<=', $percentage)
                                      ->where('max_percent', '>=', $percentage)
                                      ->first();
                    $finalGrade = $grade ? $grade->grade : null;
                }

                StudentExamMark::updateOrCreate(
                    [
                        'student_id' => $markData['student_id'],
                        'exam_structure_id' => $structure->id
                    ],
                    [
                        'component_marks' => $markData['component_marks'] ?? [],
                        'total_obtained' => $obtained,
                        'grade_obtained' => $finalGrade,
                        'attendance_status' => $markData['attendance_status'],
                        'teacher_remarks' => $markData['teacher_remarks'] ?? null
                    ]
                );
            }
        });

        return response()->json(['success' => true, 'message' => 'Marks saved successfully']);
    }

    public function publishMarks(Request $request)
    {
        $request->validate([
            'exam_structure_id' => 'required|exists:exam_structures,id',
            'is_published' => 'required|boolean'
        ]);

        $structure = ExamStructure::findOrFail($request->exam_structure_id);
        $userId = $request->user()->id;
        $user = $request->user();

        // RBAC Check: Only Class Teacher of this class OR Admin can publish
        $class = $structure->schoolClass;
        $isClassTeacher = ($class->class_teacher_id == $userId);
        $isPowerUser = $user->hasPermission('academic.update');

        if (!$isClassTeacher && !$isPowerUser) {
            return response()->json(['success' => false, 'message' => 'Unauthorized: Only the Class Teacher or Administrator can publish marks.'], 403);
        }

        $structure->update(['is_published' => $request->is_published]);

        $status = $request->is_published ? 'published' : 'unpublished';
        return response()->json(['success' => true, 'message' => "Marks successfully {$status} and will now be visible to students."]);
    }

    public function submitScholastic(Request $request)
    {
        $validated = $request->validate([
            'school_class_id' => 'required|exists:school_classes,id',
            'session_id' => 'required|exists:sessions,id',
            'category' => 'required|string',
            'grades' => 'required|array',
            'grades.*.student_id' => 'required|exists:students,id',
            'grades.*.grade' => 'required|string|max:10'
        ]);

        $userId = $request->user()->id;
        $class = SchoolClass::findOrFail($validated['school_class_id']);

        $user = $request->user();
        $isPowerUser = $user->hasPermission('academic.update');

        // Only Class Teacher or Admin can do scholastic assessments for a class
        if ($class->class_teacher_id != $userId && !$isPowerUser) {
            return response()->json(['success' => false, 'message' => 'Unauthorized: Only the class teacher can perform co-scholastic assessments.'], 403);
        }

        DB::transaction(function() use ($validated) {
            foreach ($validated['grades'] as $gradeData) {
                \App\Models\ScholasticAssessment::updateOrCreate(
                    [
                        'student_id' => $gradeData['student_id'],
                        'session_id' => $validated['session_id'],
                        'category' => $validated['category']
                    ],
                    [
                        'grade' => $gradeData['grade']
                    ]
                );
            }
        });

        return response()->json(['success' => true, 'message' => 'Scholastic grades updated successfully']);
    }
}
