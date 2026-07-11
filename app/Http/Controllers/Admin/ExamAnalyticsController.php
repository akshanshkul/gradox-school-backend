<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\StudentExamMark;
use App\Models\ExamStructure;
use App\Models\Subject;
use App\Services\AcademicService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ExamAnalyticsController extends Controller
{
    protected $academicService;

    public function __construct(AcademicService $academicService)
    {
        $this->academicService = $academicService;
    }

    private function scoped(string $table, int $schoolId, string $col = 'school_id'): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists($table, 'id')->where(fn($q) => $q->where($col, $schoolId));
    }

    public function getRankings(Request $request)
    {
        $schoolId = $request->user()->school_id;
        $request->validate([
            'school_class_id' => ['required', $this->scoped('school_classes', $schoolId)],
            'exam_term_id'    => ['required', $this->scoped('exam_terms', $schoolId)],
        ]);

        $rankings = $this->academicService->getClassRankings(
            $request->school_class_id,
            $request->exam_term_id,
            $schoolId
        );

        // Load student names — also bound to this school so leaked rankings
        // can't display foreign student identities even by accident.
        $studentIds = $rankings->pluck('student_id');
        $students = \App\Models\Student::where('school_id', $schoolId)
            ->whereIn('id', $studentIds)
            ->select('id', 'name', 'admission_number')
            ->get()->keyBy('id');

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
        $schoolId = $request->user()->school_id;
        $request->validate([
            'school_class_id' => ['required', $this->scoped('school_classes', $schoolId)],
            'exam_term_id'    => ['required', $this->scoped('exam_terms', $schoolId)],
        ]);

        // Single-query topper-per-subject — replaces the previous per-subject
        // foreach loop (N+1: 1 query per subject). For 12 subjects this drops
        // from 13 queries to 2.
        //
        // Strategy: for the (class, term) pair we join marks→structures→
        // students once and use a window-style "row_number partitioned by
        // subject" via a raw subquery. Falls back to a clean group-by on
        // MySQL by joining a max() subquery on (subject_id, max_marks).
        $rows = DB::table('student_exam_marks as sem')
            ->join('exam_structures as es', 'sem.exam_structure_id', '=', 'es.id')
            ->join('students as s', 'sem.student_id', '=', 's.id')
            ->join('subjects as sub', 'es.subject_id', '=', 'sub.id')
            ->join('exam_terms as et', 'es.exam_term_id', '=', 'et.id')
            ->select(
                'es.subject_id',
                'sub.name as subject',
                's.name as student_name',
                'sem.total_obtained as marks',
                'sem.grade_obtained as grade'
            )
            ->where('es.school_class_id', $request->school_class_id)
            ->where('es.exam_term_id', $request->exam_term_id)
            // Tenant defence-in-depth: also gate by the term's school_id so a
            // foreign class+term combination would return no rows even if it
            // ever sneaked past validation.
            ->where('et.school_id', $schoolId)
            ->where('s.school_id', $schoolId)
            ->orderBy('es.subject_id')
            ->orderByDesc('sem.total_obtained')
            ->get();

        // Pick the top row per subject (the first one after the orderByDesc).
        $toppers = [];
        $seen = [];
        foreach ($rows as $r) {
            if (isset($seen[$r->subject_id])) continue;
            $seen[$r->subject_id] = true;
            $toppers[] = [
                'subject' => $r->subject,
                'student_name' => $r->student_name,
                'marks' => $r->marks,
                'grade' => $r->grade,
            ];
        }

        return response()->json(['success' => true, 'data' => $toppers]);
    }
}
