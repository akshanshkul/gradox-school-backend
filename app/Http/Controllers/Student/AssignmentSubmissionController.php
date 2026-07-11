<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\AssignmentSubmission;
use App\Models\Homework;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Student-side endpoints for assignment PDF submissions.
 *
 * Each method is mounted under the `auth:student` guard via
 * routes/api.php. The student login carries a `$user->student`
 * relation; we never trust an arbitrary student_id from the
 * request body.
 *
 * Storage:
 *   - PDF files are written to S3 under
 *     `school-{school_id}/assignments/{homework_id}/{student_id}-{ts}.pdf`
 *   - We persist the bare S3 key in `file_path` so we can rotate URLs
 *     (signed / unsigned) later, and the last-issued public URL in
 *     `file_url` for the convenience of the student app + teacher
 *     dashboard.
 *
 * Authorization:
 *   - A student may only submit to an assignment in their CURRENT
 *     class for the ACTIVE academic session.
 *   - A student may only view their own submission.
 *
 * Resubmission policy:
 *   - One submission row per (homework, student). Resubmits overwrite
 *     the existing row in place. The old S3 object is best-effort
 *     deleted; failure is logged but does not block the new submit.
 */
class AssignmentSubmissionController extends Controller
{
    /**
     * POST /students/assignments/{id}/submit
     *
     * Body (multipart):
     *   - file: required, mimes:pdf, max 10 MB
     */
    public function submit(Request $request, $homeworkId)
    {
        $login = $request->user();
        $student = $login->student;
        if (!$student) {
            return $this->errorResponse('Student record not found', 404);
        }

        // Load the homework row, scoped to the student's school. This
        // is the multi-tenant guard — even if the student guesses an
        // id from another school the findOrFail will 404.
        $homework = Homework::where('school_id', $student->school_id)
            ->findOrFail($homeworkId);

        if (!$homework->isAssignment()) {
            return $this->errorResponse('This is not a submittable assignment.', 422);
        }
        if ($homework->status !== 'active') {
            return $this->errorResponse('This assignment is no longer accepting submissions.', 422);
        }

        // Verify the student actually belongs to the assignment's
        // class in the current session. Without this check a
        // graduated student could keep submitting to old assignments.
        $school = $student->school;
        $session = $school?->getActiveSession();
        $currentRecord = $session
            ? $student->academicRecords()->where('academic_year', $session->id)->first()
            : null;
        if (!$currentRecord || (int) $currentRecord->school_class_id !== (int) $homework->school_class_id) {
            return $this->errorResponse('This assignment is not for your class.', 403);
        }

        $request->validate([
            // 10 MB cap. PDF only — keep parity with what the teacher
            // dashboard knows how to preview.
            'file' => 'required|file|mimes:pdf|max:10240',
        ]);

        try {
            // S3 key shape — bucket-relative, no leading slash. The
            // timestamp suffix makes the key unique across resubmits
            // so we don't have to worry about CDN cache poisoning
            // on the previous version.
            $ts  = now()->format('YmdHis');
            $dir = sprintf(
                'school-%d/assignments/%d',
                $student->school_id,
                $homework->id
            );
            $filename = sprintf('%d-%s.pdf', $student->id, $ts);

            $path = $request->file('file')->storeAs($dir, $filename, 's3');
            if (!$path) {
                return $this->errorResponse('Could not upload the file. Please try again.', 500);
            }

            $url = Storage::disk('s3')->url($path);

            // Upsert by (homework_id, student_id). updateOrCreate
            // handles both first-submit and resubmit in one call.
            $previous = AssignmentSubmission::where('homework_id', $homework->id)
                ->where('student_id', $student->id)
                ->first();

            $sub = AssignmentSubmission::updateOrCreate(
                [
                    'homework_id' => $homework->id,
                    'student_id'  => $student->id,
                ],
                [
                    'school_id'    => $student->school_id,
                    'file_path'    => $path,
                    'file_url'     => $url,
                    'file_name'    => $request->file('file')->getClientOriginalName(),
                    'file_size'    => $request->file('file')->getSize(),
                    'mime_type'    => $request->file('file')->getMimeType(),
                    'submitted_at' => now(),
                    // On resubmit we INTENTIONALLY reset grading: a
                    // new submission means a new grade is owed. The
                    // teacher dashboard will show it as ungraded again.
                    'marks'        => null,
                    'feedback'     => null,
                    'graded_by'    => null,
                    'graded_at'    => null,
                ]
            );

            // Best-effort cleanup of the previous S3 object. We don't
            // delete it UNTIL the new submission is safely written
            // (avoids stranding the student with nothing if S3 hiccups
            // between delete and insert).
            if ($previous && $previous->file_path && $previous->file_path !== $path) {
                try {
                    Storage::disk('s3')->delete($previous->file_path);
                } catch (\Throwable $e) {
                    Log::warning('Failed to delete previous submission from S3', [
                        'student_id'    => $student->id,
                        'homework_id'   => $homework->id,
                        'previous_path' => $previous->file_path,
                        'error'         => $e->getMessage(),
                    ]);
                }
            }

            return $this->successResponse($sub->fresh(), 'Submission received.');
        } catch (\Throwable $e) {
            Log::error('Assignment submission failed', [
                'student_id'  => $student->id,
                'homework_id' => $homework->id,
                'error'       => $e->getMessage(),
            ]);
            return $this->errorResponse('Could not submit. Please try again.', 500);
        }
    }

    /**
     * GET /students/assignments/{id}/submission
     *
     * Returns the current student's submission row for this
     * assignment (or 404 if they haven't submitted yet).
     */
    public function show(Request $request, $homeworkId)
    {
        $login = $request->user();
        $student = $login->student;
        if (!$student) {
            return $this->errorResponse('Student record not found', 404);
        }

        $sub = AssignmentSubmission::where('school_id', $student->school_id)
            ->where('homework_id', $homeworkId)
            ->where('student_id', $student->id)
            ->first();

        if (!$sub) {
            return $this->errorResponse('No submission yet.', 404);
        }

        return $this->successResponse($sub);
    }
}
