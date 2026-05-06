<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ExamType;
use App\Models\ExamTerm;
use App\Models\GradingScale;
use App\Models\ExamStructure;
use App\Models\ExamStructureComponent;
use Illuminate\Support\Facades\DB;

class ExamConfigurationController extends Controller
{
    public function getTerms(Request $request)
    {
        $terms = ExamTerm::where('school_id', $request->user()->school_id)
            ->with('session')
            ->orderBy('created_at', 'desc')
            ->get();
        return response()->json(['success' => true, 'data' => $terms]);
    }

    public function storeTerm(Request $request)
    {
        $validated = $request->validate([
            'session_id' => 'required|exists:sessions,id',
            'name' => 'required|string|max:255',
            'weightage' => 'required|numeric|min:0|max:100',
            'is_active' => 'boolean'
        ]);

        $validated['school_id'] = $request->user()->school_id;
        $term = ExamTerm::create($validated);

        return response()->json(['success' => true, 'message' => 'Exam term created successfully', 'data' => $term]);
    }

    public function getTypes(Request $request)
    {
        $types = ExamType::where('school_id', $request->user()->school_id)->get();
        return response()->json(['success' => true, 'data' => $types]);
    }

    public function storeType(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $validated['school_id'] = $request->user()->school_id;
        $type = ExamType::create($validated);

        return response()->json(['success' => true, 'message' => 'Exam type created successfully', 'data' => $type]);
    }

    public function getGradingScales(Request $request)
    {
        $scales = GradingScale::where('school_id', $request->user()->school_id)
            ->orderBy('min_percent', 'desc')
            ->get();
        return response()->json(['success' => true, 'data' => $scales]);
    }

    public function storeGradingScale(Request $request)
    {
        $validated = $request->validate([
            'min_percent' => 'required|numeric|min:0|max:100',
            'max_percent' => 'required|numeric|min:0|max:100',
            'grade' => 'required|string|max:10',
            'grade_point' => 'nullable|numeric',
            'description' => 'nullable|string'
        ]);

        $validated['school_id'] = $request->user()->school_id;
        $scale = GradingScale::create($validated);

        return response()->json(['success' => true, 'message' => 'Grading scale added', 'data' => $scale]);
    }

    public function getStructures(Request $request)
    {
        $query = ExamStructure::whereHas('term', function($q) use ($request) {
            $q->where('school_id', $request->user()->school_id);
        })->with(['term', 'type', 'schoolClass.grade', 'schoolClass.section', 'subject', 'components']);

        if ($request->exam_term_id) {
            $query->where('exam_term_id', $request->exam_term_id);
        }

        if ($request->school_class_id) {
            $query->where('school_class_id', $request->school_class_id);
        }

        return response()->json(['success' => true, 'data' => $query->get()]);
    }

    public function storeStructure(Request $request)
    {
        $validated = $request->validate([
            'exam_term_id' => 'required|exists:exam_terms,id',
            'exam_type_id' => 'required|exists:exam_types,id',
            'school_class_id' => 'required|exists:school_classes,id',
            'subject_id' => 'required|exists:subjects,id',
            'scoring_type' => 'required|in:marks,grade',
            'passing_marks' => 'required|integer',
            'components' => 'required|array|min:1',
            'components.*.name' => 'required|string',
            'components.*.max_marks' => 'required|integer|min:1'
        ]);

        return DB::transaction(function() use ($validated) {
            $structure = ExamStructure::updateOrCreate(
                [
                    'exam_term_id' => $validated['exam_term_id'],
                    'exam_type_id' => $validated['exam_type_id'],
                    'school_class_id' => $validated['school_class_id'],
                    'subject_id' => $validated['subject_id'],
                ],
                [
                    'scoring_type' => $validated['scoring_type'],
                    'passing_marks' => $validated['passing_marks'],
                ]
            );

            // Sync components
            $structure->components()->delete();
            foreach ($validated['components'] as $comp) {
                $structure->components()->create($comp);
            }

            return response()->json(['success' => true, 'message' => 'Exam structure saved successfully', 'data' => $structure->load('components')]);
        });
    }

    public function storeStructureBatch(Request $request)
    {
        $validated = $request->validate([
            'exam_term_id' => 'required|exists:exam_terms,id',
            'exam_type_id' => 'required|exists:exam_types,id',
            'school_class_id' => 'required|exists:school_classes,id',
            'subject_ids' => 'required|array|min:1',
            'subject_ids.*' => 'required|exists:subjects,id',
            'scoring_type' => 'required|in:marks,grade',
            'passing_marks' => 'required|integer',
            'components' => 'required|array|min:1',
            'components.*.name' => 'required|string',
            'components.*.max_marks' => 'required|integer|min:1'
        ]);

        return DB::transaction(function() use ($validated) {
            $created = [];
            foreach ($validated['subject_ids'] as $subjectId) {
                $structure = ExamStructure::updateOrCreate(
                    [
                        'exam_term_id' => $validated['exam_term_id'],
                        'exam_type_id' => $validated['exam_type_id'],
                        'school_class_id' => $validated['school_class_id'],
                        'subject_id' => $subjectId,
                    ],
                    [
                        'scoring_type' => $validated['scoring_type'],
                        'passing_marks' => $validated['passing_marks'],
                    ]
                );

                $structure->components()->delete();
                foreach ($validated['components'] as $comp) {
                    $structure->components()->create($comp);
                }
                $created[] = $structure->id;
            }

            return response()->json([
                'success' => true, 
                'message' => 'Batch setup completed for ' . count($created) . ' subjects',
                'data' => $created
            ]);
        });
    }

    public function cloneStructure(Request $request)
    {
        $validated = $request->validate([
            'source_term_id' => 'required|exists:exam_terms,id',
            'target_term_id' => 'required|exists:exam_terms,id',
        ]);

        $sources = ExamStructure::where('exam_term_id', $validated['source_term_id'])->with('components')->get();
        
        DB::transaction(function() use ($sources, $validated) {
            foreach ($sources as $source) {
                $new = $source->replicate();
                $new->exam_term_id = $validated['target_term_id'];
                $new->save();

                foreach ($source->components as $comp) {
                    $newComp = $comp->replicate();
                    $newComp->exam_structure_id = $new->id;
                    $newComp->save();
                }
            }
        });

        return response()->json(['success' => true, 'message' => "Cloned " . $sources->count() . " structures successfully"]);
    }

    public function togglePublication(Request $request, $id)
    {
        $structure = ExamStructure::findOrFail($id);
        $structure->is_published = !$structure->is_published;
        $structure->save();

        return response()->json([
            'success' => true,
            'message' => $structure->is_published ? 'Result published successfully' : 'Result hidden from students',
            'data' => $structure
        ]);
    }

    public function batchTogglePublication(Request $request)
    {
        $validated = $request->validate([
            'school_class_id' => 'required|exists:school_classes,id',
            'exam_term_id' => 'required|exists:exam_terms,id',
            'publish' => 'required|boolean'
        ]);

        $count = ExamStructure::where('school_class_id', $validated['school_class_id'])
            ->where('exam_term_id', $validated['exam_term_id'])
            ->update(['is_published' => $validated['publish']]);

        return response()->json([
            'success' => true,
            'message' => ($validated['publish'] ? 'Published' : 'Hidden') . " results for $count subjects."
        ]);
    }
}
