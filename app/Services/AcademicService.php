<?php

namespace App\Services;

use App\Models\Student;
use App\Models\ExamTerm;
use App\Models\StudentExamMark;
use App\Models\ExamStructure;
use Illuminate\Support\Facades\DB;

class AcademicService
{
    /**
     * Calculate a student's final session result based on term weightage.
     */
    public function calculateFinalResult($studentId, $sessionId)
    {
        $terms = ExamTerm::where('session_id', $sessionId)
            ->where('school_id', function($q) use ($studentId) {
                $q->select('school_id')->from('students')->where('id', $studentId);
            })
            ->get();

        $totalWeightedScore = 0;
        $totalWeightApplied = 0;

        foreach ($terms as $term) {
            $termTotal = $this->getTermTotal($studentId, $term->id);
            if ($termTotal['max_marks'] > 0) {
                $percentage = ($termTotal['obtained'] / $termTotal['max_marks']) * 100;
                $totalWeightedScore += ($percentage * ($term->weightage / 100));
                $totalWeightApplied += $term->weightage;
            }
        }

        return [
            'final_percentage' => $totalWeightApplied > 0 ? ($totalWeightedScore / ($totalWeightApplied / 100)) : 0,
            'total_weight_applied' => $totalWeightApplied
        ];
    }

    protected function getTermTotal($studentId, $termId)
    {
        $marks = StudentExamMark::where('student_id', $studentId)
            ->whereHas('structure', function($q) use ($termId) {
                $q->where('exam_term_id', $termId);
            })
            ->with('structure.components')
            ->get();

        $obtained = $marks->sum('total_obtained');
        $max = $marks->sum(function($m) {
            return $m->structure->components->sum('max_marks');
        });

        return ['obtained' => $obtained, 'max_marks' => $max];
    }

    /**
     * Get class rankings for a specific exam term.
     */
    public function getClassRankings($schoolClassId, $examTermId)
    {
        $rankings = DB::table('student_exam_marks as sem')
            ->join('exam_structures as es', 'sem.exam_structure_id', '=', 'es.id')
            ->select('sem.student_id', DB::raw('SUM(sem.total_obtained) as total_score'))
            ->where('es.school_class_id', $schoolClassId)
            ->where('es.exam_term_id', $examTermId)
            ->groupBy('sem.student_id')
            ->orderBy('total_score', 'desc')
            ->get();

        return $rankings->map(function($item, $index) {
            $item->rank = $index + 1;
            return $item;
        });
    }
}
