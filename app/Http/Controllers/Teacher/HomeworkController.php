<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\AssignmentSubmission;
use App\Models\Homework;
use App\Models\SchoolClass;
use Illuminate\Http\Request;

/**
 * Homework + Assignment CRUD — staff/teacher facing endpoints.
 *
 * The system models two distinct "kinds" of work in the same table:
 *   - kind=homework   — short text the teacher posts FOR a particular
 *                       calendar day (`for_date`). No submission.
 *   - kind=assignment — longer brief with a `due_date` and a PDF
 *                       submission flow handled by
 *                       AssignmentSubmissionController.
 *
 * Authorization model (per request, per row):
 *   - School admins (`isAdmin()` = true) can see / edit / delete /
 *     grade any row in their school.
 *   - Teachers can see rows they CREATED themselves OR rows assigned
 *     to a class they are the class-teacher of, and can only edit /
 *     delete / grade rows they created.
 *
 * Multi-tenant: every query is rooted in `school_id = $user->school_id`.
 * No path here ever reads across schools, including admin's index.
 */
class HomeworkController extends Controller
{
    /**
     * Form options for the create screen.
     *
     * Returns the classes this teacher can post homework to + their
     * subject lists. A class is "targetable" if the teacher is either
     * the class teacher OR teaches any subject in that class
     * (class_subject.teacher_id = user.id).
     *
     * Admins see all classes in the school.
     *
     * Shape: { classes: [{ id, label, subjects: [{ id, name }] }] }
     */
    public function options(Request $request)
    {
        $user = $request->user();

        $query = SchoolClass::where('school_id', $user->school_id)
            ->with([
                'grade:id,name',
                'section:id,name',
                // Pull subjects with the teacher pivot so we can filter
                // them down to "subjects this teacher actually teaches"
                // in non-admin mode.
                'subjects' => function ($q) {
                    $q->select('subjects.id', 'subjects.name');
                },
            ]);

        if (!$user->isAdmin()) {
            // Either I'm the class teacher OR I teach at least one
            // subject in this class. Union via two whereIn lookups.
            $taughtClassIds = \App\Models\ClassSubject::where('teacher_id', $user->id)
                ->pluck('school_class_id')->unique()->toArray();
            $managedClassIds = SchoolClass::where('class_teacher_id', $user->id)
                ->pluck('id')->toArray();
            $allIds = array_unique(array_merge($taughtClassIds, $managedClassIds));

            $query->whereIn('id', $allIds);
        }

        $classes = $query->get()->map(function ($c) use ($user) {
            // Subject visibility rules — three concentric tiers:
            //
            //   1. Admin            → all subjects mapped to the class.
            //   2. Class teacher    → all subjects mapped to the class
            //                          they're the class teacher of.
            //                          Even if every subject in that
            //                          class is personally taught by
            //                          someone else, the class teacher
            //                          still has authority over what's
            //                          posted to their class.
            //   3. Subject teacher  → only subjects where the
            //                          class_subject pivot's
            //                          teacher_id matches them.
            //
            // Before this rule, a class teacher whose own class had
            // subjects taught by other teachers saw an EMPTY subject
            // picker — making it impossible to post any homework for
            // their own class from the teacher app, while the web
            // admin (which doesn't apply this filter at all) showed
            // the subject just fine. That asymmetry is the bug.
            $subjects = $c->subjects;
            $isAdmin = $user->isAdmin();
            $isClassTeacher = (int) ($c->class_teacher_id ?? 0) === (int) $user->id;
            if (!$isAdmin && !$isClassTeacher) {
                $subjects = $subjects->filter(fn($s) => (int) $s->pivot->teacher_id === (int) $user->id);
            }
            return [
                'id'       => $c->id,
                'label'    => trim(($c->grade?->name ?? '') . ' - ' . ($c->section?->name ?? ''), ' -'),
                'subjects' => $subjects->map(fn($s) => ['id' => $s->id, 'name' => $s->name])->values(),
            ];
        })->values();

        return $this->successResponse(['classes' => $classes]);
    }

    /** List homework + assignments visible to this teacher / admin. */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Homework::where('school_id', $user->school_id)
            ->with([
                'schoolClass.grade',
                'schoolClass.section',
                'subject:id,name',
                'creator:id,name',
            ])
            // Pre-compute the submission count per row so the teacher
            // UI can show "12 submitted / 30" without N+1 queries.
            ->withCount('submissions');

        if (!$user->isAdmin()) {
            $managedClassIds = SchoolClass::where('class_teacher_id', $user->id)
                ->pluck('id')->toArray();

            $query->where(function ($q) use ($user, $managedClassIds) {
                $q->where('created_by', $user->id)
                  ->orWhereIn('school_class_id', $managedClassIds);
            });
        }

        // Allow ?kind=homework | ?kind=assignment to filter — the
        // teacher app uses two tabs and each calls this with the
        // narrower filter for tighter responses.
        if ($request->filled('kind') && in_array($request->kind, ['homework', 'assignment'])) {
            $query->where('kind', $request->kind);
        }

        // Sort key depends on the kind. Assignments have due_date,
        // homework has for_date; coalesce so a mixed list still
        // sorts consistently (newest deadline / day first).
        $homework = $query
            ->orderByRaw('COALESCE(due_date, for_date, created_at) DESC')
            ->get();

        return $this->successResponse($homework);
    }

    /** Show a single homework / assignment row — same auth as index. */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $homework = Homework::with([
            'schoolClass.grade', 'schoolClass.section',
            'subject:id,name', 'creator:id,name',
        ])
            ->withCount('submissions')
            ->where('school_id', $user->school_id)
            ->findOrFail($id);

        if (!$this->canView($user, $homework)) {
            return $this->errorResponse('Not authorized to view this homework.', 403);
        }

        return $this->successResponse($homework);
    }

    /** Create a new homework or assignment. */
    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'school_class_id' => 'required|exists:school_classes,id',
            'subject_id'      => 'nullable|exists:subjects,id',
            'kind'            => 'nullable|in:homework,assignment',
            'title'           => 'required|string|max:255',
            'description'     => 'nullable|string',
            // Validation depends on `kind`: homework needs for_date,
            // assignment needs due_date. We use Laravel's
            // required_if to encode that without writing two
            // validators.
            'for_date'        => 'nullable|date|required_if:kind,homework',
            'due_date'        => 'nullable|date|required_if:kind,assignment',
        ]);

        // Default kind = 'homework' if caller didn't specify (back-
        // compat with the old API contract where everything was
        // homework-ish).
        $kind = $data['kind'] ?? 'homework';

        // Ensure the target class belongs to THIS school (don't trust
        // FK existence alone — a user with school A's token must not
        // be able to attach homework to school B's class).
        $class = SchoolClass::where('id', $data['school_class_id'])
            ->where('school_id', $user->school_id)->first();
        if (!$class) {
            return $this->errorResponse('Selected class does not belong to your school.', 422);
        }

        $homework = Homework::create([
            'school_id'       => $user->school_id,
            'created_by'      => $user->id,
            'school_class_id' => $data['school_class_id'],
            'subject_id'      => $data['subject_id'] ?? null,
            'kind'            => $kind,
            'title'           => $data['title'],
            'description'     => $data['description'] ?? null,
            'for_date'        => $kind === 'homework'   ? ($data['for_date'] ?? null) : null,
            'due_date'        => $kind === 'assignment' ? ($data['due_date'] ?? null) : null,
        ]);

        return $this->successResponse(
            $homework->load(['schoolClass.grade', 'schoolClass.section', 'subject:id,name']),
            ucfirst($kind) . ' created successfully', 201
        );
    }

    /** Update an existing row — only the creator (or admin). */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        $homework = Homework::where('school_id', $user->school_id)->findOrFail($id);

        if (!$this->canEdit($user, $homework)) {
            return $this->errorResponse('Only the teacher who created this can edit it.', 403);
        }

        $data = $request->validate([
            'school_class_id' => 'sometimes|exists:school_classes,id',
            'subject_id'      => 'sometimes|nullable|exists:subjects,id',
            'kind'            => 'sometimes|in:homework,assignment',
            'title'           => 'sometimes|string|max:255',
            'description'     => 'sometimes|nullable|string',
            'for_date'        => 'sometimes|nullable|date',
            'due_date'        => 'sometimes|nullable|date',
            'status'          => 'sometimes|in:active,archived',
        ]);

        // Same cross-tenant guard as store().
        if (array_key_exists('school_class_id', $data)) {
            $class = SchoolClass::where('id', $data['school_class_id'])
                ->where('school_id', $user->school_id)->first();
            if (!$class) {
                return $this->errorResponse('Selected class does not belong to your school.', 422);
            }
        }

        $homework->update($data);

        return $this->successResponse(
            $homework->fresh(['schoolClass.grade', 'schoolClass.section', 'subject:id,name']),
            'Updated successfully'
        );
    }

    /** Delete a row — only the creator (or admin). */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $homework = Homework::where('school_id', $user->school_id)->findOrFail($id);

        if (!$this->canEdit($user, $homework)) {
            return $this->errorResponse('Only the teacher who created this can delete it.', 403);
        }

        $homework->delete();
        return $this->successResponse(null, 'Deleted successfully');
    }

    /**
     * List submissions for a single assignment.
     *
     * Returns one row per student in the class — those who haven't
     * submitted appear with `submission: null` so the teacher UI can
     * render a "pending" row for them without a second query.
     *
     * Only meaningful when the parent row is kind='assignment';
     * we return an empty array (and a 200, not a 404) when called
     * on a homework row so the UI can degrade gracefully.
     */
    public function submissions(Request $request, $id)
    {
        $user = $request->user();
        $homework = Homework::where('school_id', $user->school_id)->findOrFail($id);

        if (!$this->canView($user, $homework)) {
            return $this->errorResponse('Not authorized to view submissions.', 403);
        }
        if (!$homework->isAssignment()) {
            return $this->successResponse(['rows' => [], 'kind' => $homework->kind]);
        }

        // Pull the roster (current academic year, this class). We
        // outer-join submissions so unsubmitted students still
        // appear in the list with a NULL submission.
        $school = $user->school;
        $session = $school?->getActiveSession();
        if (!$session) {
            return $this->successResponse(['rows' => [], 'kind' => 'assignment']);
        }

        // NOTE: `roll_number` lives on student_academic_records (it
        // varies per session — a student gets a new roll each year),
        // NOT on the students table. Selecting it from `students`
        // would throw "Unknown column 'roll_number'". Pull the roll
        // off the academic record row itself instead.
        $records = \App\Models\StudentAcademicRecord::where('school_class_id', $homework->school_class_id)
            ->where('academic_year', $session->id)
            ->with(['student:id,name,photo_path'])
            ->get();

        $subs = AssignmentSubmission::where('homework_id', $homework->id)
            ->where('school_id', $user->school_id)
            ->get()
            ->keyBy('student_id');

        $rows = $records->map(function ($r) use ($subs) {
            $sub = $subs->get($r->student_id);
            return [
                'student_id'   => $r->student_id,
                'student_name' => $r->student?->name,
                'roll_number'  => $r->roll_number,
                'photo_path'   => $r->student?->photo_path,
                'submission'   => $sub ? [
                    'id'           => $sub->id,
                    'file_url'     => $sub->file_url,
                    'file_name'    => $sub->file_name,
                    'file_size'    => $sub->file_size,
                    'submitted_at' => $sub->submitted_at?->toIso8601String(),
                    'marks'        => $sub->marks,
                    'feedback'     => $sub->feedback,
                    'graded_at'    => $sub->graded_at?->toIso8601String(),
                ] : null,
            ];
        })->sortBy('roll_number')->values();

        return $this->successResponse([
            'kind'    => 'assignment',
            'due_date'=> $homework->due_date,
            'rows'    => $rows,
        ]);
    }

    /**
     * Grade / give feedback on a single submission.
     *
     * Same authorization as editing the parent homework: only the
     * teacher who created the assignment (or an admin) can grade it.
     */
    public function gradeSubmission(Request $request, $submissionId)
    {
        $user = $request->user();

        $sub = AssignmentSubmission::where('school_id', $user->school_id)
            ->findOrFail($submissionId);
        $homework = Homework::where('school_id', $user->school_id)->findOrFail($sub->homework_id);

        if (!$this->canEdit($user, $homework)) {
            return $this->errorResponse('Only the creator can grade this submission.', 403);
        }

        $data = $request->validate([
            'marks'    => 'nullable|numeric|min:0|max:9999.99',
            'feedback' => 'nullable|string|max:2000',
        ]);

        $sub->update([
            'marks'     => $data['marks']    ?? $sub->marks,
            'feedback'  => $data['feedback'] ?? $sub->feedback,
            'graded_by' => $user->id,
            'graded_at' => now(),
        ]);

        return $this->successResponse($sub->fresh(), 'Submission graded');
    }

    /** --- visibility / edit policies (kept small + central) --- */

    private function canView($user, Homework $homework): bool
    {
        if ($user->isAdmin()) return true;
        if ((int) $homework->created_by === (int) $user->id) return true;
        return SchoolClass::where('id', $homework->school_class_id)
            ->where('class_teacher_id', $user->id)->exists();
    }

    private function canEdit($user, Homework $homework): bool
    {
        if ($user->isAdmin()) return true;
        return (int) $homework->created_by === (int) $user->id;
    }
}
