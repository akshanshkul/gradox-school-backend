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
use Illuminate\Validation\Rule;

class ExamConfigurationController extends Controller
{
    /** Reusable: school-scoped exists rule. Centralised so the pattern can't
     *  drift between methods. NOTE the return type — Rule::exists() returns
     *  an Illuminate\Validation\Rules\Exists instance, not the Rule facade. */
    private function scoped(string $table, int $schoolId, string $col = 'school_id'): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists($table, 'id')->where(fn($q) => $q->where($col, $schoolId));
    }
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
        $schoolId = $request->user()->school_id;
        $validated = $request->validate([
            'session_id' => ['required', $this->scoped('sessions', $schoolId)],
            'name' => 'required|string|max:255',
            'weightage' => 'required|numeric|min:0|max:100',
            'is_active' => 'boolean'
        ]);

        $validated['school_id'] = $schoolId;
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
        $schoolId = $request->user()->school_id;
        $validated = $request->validate([
            'exam_term_id'    => ['required', $this->scoped('exam_terms', $schoolId)],
            'exam_type_id'    => ['required', $this->scoped('exam_types', $schoolId)],
            'school_class_id' => ['required', $this->scoped('school_classes', $schoolId)],
            'subject_id'      => ['required', $this->scoped('subjects', $schoolId)],
            'scoring_type'    => 'required|in:marks,grade',
            'passing_marks'   => 'required|integer',
            'components'      => 'required|array|min:1',
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
        $schoolId = $request->user()->school_id;
        $validated = $request->validate([
            'exam_term_id'    => ['required', $this->scoped('exam_terms', $schoolId)],
            'exam_type_id'    => ['required', $this->scoped('exam_types', $schoolId)],
            'school_class_id' => ['required', $this->scoped('school_classes', $schoolId)],
            'subject_ids'     => 'required|array|min:1',
            'subject_ids.*'   => ['required', $this->scoped('subjects', $schoolId)],
            'scoring_type'    => 'required|in:marks,grade',
            'passing_marks'   => 'required|integer',
            'components'      => 'required|array|min:1',
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
        $schoolId = $request->user()->school_id;
        $validated = $request->validate([
            'source_term_id' => ['required', $this->scoped('exam_terms', $schoolId)],
            'target_term_id' => ['required', $this->scoped('exam_terms', $schoolId)],
        ]);

        // Defensive: also bound the source query to terms in this school in
        // case a future code path lets a foreign term sneak past validation.
        $sources = ExamStructure::where('exam_term_id', $validated['source_term_id'])
            ->whereHas('term', fn($q) => $q->where('school_id', $schoolId))
            ->with('components')
            ->get();

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
        // Tenant guard: scope by the parent term's school_id. The original
        // findOrFail() would happily toggle a publication state on any
        // structure id, including one belonging to another school.
        $schoolId = $request->user()->school_id;
        $structure = ExamStructure::whereHas('term', fn($q) => $q->where('school_id', $schoolId))
            ->findOrFail($id);
        $structure->is_published = !$structure->is_published;
        $structure->save();

        return response()->json([
            'success' => true,
            'message' => $structure->is_published ? 'Result published successfully' : 'Result hidden from students',
            'data' => $structure
        ]);
    }

    /**
     * Delete an academic term. Refuses if any exam structure (and therefore
     * possibly marks) still depend on it — those references must be cleaned
     * up first. Tenant-scoped find prevents cross-school deletes.
     */
    public function destroyTerm(Request $request, $id)
    {
        $schoolId = $request->user()->school_id;
        $term = ExamTerm::where('school_id', $schoolId)->findOrFail($id);

        $dependents = ExamStructure::where('exam_term_id', $term->id)->count();
        if ($dependents > 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete: $dependents exam structure(s) are still linked to this term. Remove those first.",
            ], 422);
        }

        $term->delete();
        return response()->json(['success' => true, 'message' => 'Term deleted.']);
    }

    /**
     * Delete an exam type. Refuses if any structure is using it.
     */
    public function destroyType(Request $request, $id)
    {
        $schoolId = $request->user()->school_id;
        $type = ExamType::where('school_id', $schoolId)->findOrFail($id);

        $dependents = ExamStructure::where('exam_type_id', $type->id)->count();
        if ($dependents > 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete: $dependents exam structure(s) are still using this type.",
            ], 422);
        }

        $type->delete();
        return response()->json(['success' => true, 'message' => 'Exam type deleted.']);
    }

    /**
     * Delete a grading-scale row. Grading scales are referenced by mark-grade
     * lookups at compute time only, so there are no FK dependents to gate on.
     */
    public function destroyGradingScale(Request $request, $id)
    {
        $schoolId = $request->user()->school_id;
        $scale = GradingScale::where('school_id', $schoolId)->findOrFail($id);
        $scale->delete();
        return response()->json(['success' => true, 'message' => 'Grading scale removed.']);
    }

    public function batchTogglePublication(Request $request)
    {
        $schoolId = $request->user()->school_id;
        $validated = $request->validate([
            'school_class_id' => ['required', $this->scoped('school_classes', $schoolId)],
            'exam_term_id'    => ['required', $this->scoped('exam_terms', $schoolId)],
            'publish'         => 'required|boolean'
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
