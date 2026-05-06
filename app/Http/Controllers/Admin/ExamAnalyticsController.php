<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\StudentExamMark;
use App\Models\ExamStructure;
use App\Models\Subject;
use App\Services\AcademicService;
use Illuminate\Support\Facades\DB;

class ExamAnalyticsController extends Controller
{
    protected $academicService;

    public function __construct(AcademicService $academicService)
    {
        $this->academicService = $academicService;
    }

    public function getRankings(Request $request)
    {
        $request->validate([
            'school_class_id' => 'required|exists:school_classes,id',
            'exam_term_id' => 'required|exists:exam_terms,id',
        ]);

        $rankings = $this->academicService->getClassRankings(
            $request->school_class_id,
            $request->exam_term_id
        );

        // Load student names
        $studentIds = $rankings->pluck('student_id');
        $students = \App\Models\Student::whereIn('id', $studentIds)->select('id', 'name', 'admission_number')->get()->keyBy('id');

        $data = $rankings->map(function($r) use ($students) {
            $s = $students[$r->student_id] ?? null;
            return [
                'rank' => $r->rank,
                'student_id' => $r->student_id,
                'name' => $s ? $s->name : 'Unknown',
                'admission_number' => $s ? $s->admission_number : 'N/A',
                'total_score' => $r->total_score
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function getToppers(Request $request)
    {
        $request->validate([
            'school_class_id' => 'required|exists:school_classes,id',
            'exam_term_id' => 'required|exists:exam_terms,id',
        ]);

        $subjects = Subject::whereIn('id', function($q) use ($request) {
            $q->select('subject_id')->from('exam_structures')
              ->where('school_class_id', $request->school_class_id)
              ->where('exam_term_id', $request->exam_term_id);
        })->get();

        $toppers = [];

        foreach ($subjects as $subject) {
            $topper = DB::table('student_exam_marks as sem')
                ->join('exam_structures as es', 'sem.exam_structure_id', '=', 'es.id')
                ->join('students as s', 'sem.student_id', '=', 's.id')
                ->select('s.name', 'sem.total_obtained', 'sem.grade_obtained')
                ->where('es.school_class_id', $request->school_class_id)
                ->where('es.exam_term_id', $request->exam_term_id)
                ->where('es.subject_id', $subject->id)
                ->orderBy('sem.total_obtained', 'desc')
                ->first();

            if ($topper) {
                $toppers[] = [
                    'subject' => $subject->name,
                    'student_name' => $topper->name,
                    'marks' => $topper->total_obtained,
                    'grade' => $topper->grade_obtained
                ];
            }
        }

        return response()->json(['success' => true, 'data' => $toppers]);
    }
}
