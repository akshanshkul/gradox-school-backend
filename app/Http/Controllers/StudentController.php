<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;


use App\Models\Student;
use App\Models\StudentAcademicRecord;
use App\Models\School;
use App\Models\StudentLogin;
use App\Models\StudentPasswordReset;
use App\Mail\StudentPasswordResetMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Models\SchoolEvent;
use App\Models\Circular;
use Carbon\Carbon;
use App\Traits\FeeLogicTrait;

class StudentController extends Controller
{
    use FeeLogicTrait;

    public function index(Request $request)
    {
        $school = $request->user()->school;
        $activeSession = $school->getActiveSession();
        $sessionId = $activeSession->id;

        $query = Student::where('school_id', $school->id)
            ->with([
                'currentRecord' => function ($q) use ($sessionId) {
                    $q->where('academic_year', $sessionId);
                },
                'currentRecord.schoolClass.grade',
                'currentRecord.schoolClass.section'
            ])
            ->orderBy('name', 'asc');

        // RBAC: Non-Admins can only see students in classes they manage
        if (!$request->user()->isAdmin() && !$request->user()->hasPermission('manage_all_students')) {
            $query->whereHas('currentRecord.schoolClass', function ($q) use ($request) {
                $q->where('class_teacher_id', $request->user()->id);
            });
        }

        if ($request->has('class_id') && !is_null($request->class_id)) {
            $query->whereHas('currentRecord', function ($q) use ($request) {
                $q->where('school_class_id', $request->class_id);
            });
        }

        if ($request->has('search') && !is_null($request->search)) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                    ->orWhere('admission_number', 'like', '%' . $request->search . '%');
            });
        }

        $perPage = (int) $request->input('per_page', 20);
        // Clamp so a malicious / broken client can't drag the whole table in
        // one shot, and so the smallest selectable page is still useful.
        if ($perPage < 10) $perPage = 10;
        if ($perPage > 200) $perPage = 200;

        return $this->successResponse($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:students,email',
            'admission_number' => 'required|string|unique:students,admission_number',
            'school_class_id' => 'required|exists:school_classes,id',
            'date_of_birth' => 'required|date',
            'gender' => 'required|in:male,female,other',
        ]);

        return DB::transaction(function () use ($request) {
            $school = $request->user()->school;

            $student = Student::create([
                'school_id' => $school->id,
                'name' => $request->name,
                'email' => $request->email,
                'admission_number' => $request->admission_number,
                'date_of_birth' => $request->date_of_birth,
                'gender' => $request->gender,
                'admission_date' => now(),
                'status' => 'active'
            ]);

            $activeSession = $school->getActiveSession();

            StudentAcademicRecord::create([
                'student_id' => $student->id,
                'school_class_id' => $request->school_class_id,
                'academic_year' => $activeSession->id,
                'roll_number' => $request->roll_number,
                'status' => 'active'
            ]);

            // Create Login (school_id required for the per-school unique constraint)
            $student->login()->create([
                'school_id' => $student->school_id,
                'admission_number' => $student->admission_number,
                'email' => $student->email,
                'password' => bcrypt(str_replace('-', '', $student->date_of_birth->format('Y-m-d')))
            ]);

            return $this->successResponse($student->load('currentRecord'), 'Student admitted successfully', 201);
        });
    }

    public function show($id, Request $request)
    {
        $schoolId = $request->user()->school_id;
        $school = $request->user()->school;
        $activeSession = $school->getActiveSession();

        $student = Student::where('school_id', $schoolId)
            ->with([
                'documents.type',
                'login'
            ])
            ->findOrFail($id);

        $academicRecords = \App\Models\StudentAcademicRecord::where('student_id', $id)
            ->with(['schoolClass.grade', 'schoolClass.section'])
            ->orderBy('created_at', 'desc')
            ->get();

        $currentRecord = $academicRecords->where('academic_year', $activeSession->id)->first();
        $lastRecord = $academicRecords->where('academic_year', '!=', $activeSession->id)->first();

        // Promotion Logic
        $student->setAttribute('is_promoted', !is_null($currentRecord));
        $student->setAttribute('current_record', $currentRecord);
        $student->setAttribute('last_record', $lastRecord);
        $student->setAttribute('academic_records', $academicRecords);

        // Fetch applicable fee assignments for active session
        $classId = $currentRecord?->school_class_id;
        $gradeId = $currentRecord?->schoolClass?->grade_id;
        $lastGradeId = $lastRecord?->schoolClass?->grade_id;

        $assignments = \App\Models\FeeAssignment::with(['feeType', 'payments' => function($q) use ($id) {
            $q->where('student_id', $id);
        }])
            ->where('school_id', $schoolId)
            ->where('session_id', $activeSession->id)
            ->where(function($q) use ($id, $classId, $gradeId, $lastGradeId) {
                // 1. Direct assignments to this student
                $q->where('student_id', $id)
                // 2. Global Session Fees (School-wide)
                  ->orWhere(function($sq) {
                      $sq->whereNull('student_id')
                         ->whereNull('grade_id')
                         ->whereNull('class_id');
                  });

                // 3. Class-specific assignments
                if ($classId) $q->orWhere('class_id', $classId);
                
                // 4. Grade-specific assignments (Current OR Last known for unpromoted students)
                if ($gradeId) {
                    $q->orWhere('grade_id', $gradeId);
                } elseif ($lastGradeId) {
                    $q->orWhere('grade_id', $lastGradeId);
                }
            })
             ->get();

        // Calculate session status
        $monthsPassed = 0;
        if ($activeSession && $activeSession->start_date) {
            $startDate = \Carbon\Carbon::parse($activeSession->start_date);
            $monthsPassed = $startDate->diffInMonths(now());
        }

        $assignments = $assignments->map(function($a) use ($monthsPassed) {
            $meta = $this->calculateInstallmentMeta($a, $monthsPassed);
            $a->setAttribute('installment_meta', $meta);
            // Flatten some meta for backward compatibility or ease of use in UI
            $a->setAttribute('paid_months_count', $meta['paid_months_count']);
            $a->setAttribute('pending_months_count', $meta['pending_months_count']);
            $a->setAttribute('is_installment_paid', $meta['is_installment_paid']);
            return $a;
        });

        $student->setAttribute('fee_assignments', $assignments);

        // Fetch recent transactions for history view
        $transactions = \App\Models\PaymentTransaction::whereHas('receipt', function($q) use ($id) {
            $q->where('student_id', $id);
        })
        ->with(['receipt.assignment.feeType'])
        ->orderBy('created_at', 'desc')
        ->take(10)
        ->get();

        $student->setAttribute('payment_history', $transactions);

        return $this->successResponse($student);
    }

    public function update(Request $request, $id)
    {
        $student = Student::where('school_id', $request->user()->school_id)->findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:students,email,' . $id,
            'admission_number' => 'required|string|unique:students,admission_number,' . $id,
            'aadhaar_number' => 'nullable|string|size:12',
            'gender' => 'required|in:male,female,other',
            'date_of_birth' => 'required|date',
            'school_class_id' => 'required|exists:school_classes,id',
            'roll_number' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request, $student) {
            $school = $request->user()->school;
            $activeSession = $school->getActiveSession();

            // 1. Update Student Basic/Bio Data
            $student->update([
                'name' => $request->name,
                'email' => $request->email,
                'admission_number' => $request->admission_number,
                'aadhaar_number' => $request->aadhaar_number,
                'phone' => $request->phone,
                'parent_name' => $request->parent_name,
                'parent_phone' => $request->parent_phone,
                'parent_occupation' => $request->parent_occupation,
                'address' => $request->address,
                'gender' => $request->gender,
                'date_of_birth' => $request->date_of_birth,
            ]);

            // 2. Update/Sync Academic Record for Current Session
            $academicRecord = StudentAcademicRecord::where('student_id', $student->id)
                ->where('academic_year', $activeSession->id)
                ->first();

            if ($academicRecord) {
                $academicRecord->update([
                    'school_class_id' => $request->school_class_id,
                    'roll_number' => $request->roll_number,
                ]);
            } else {
                StudentAcademicRecord::create([
                    'student_id' => $student->id,
                    'school_class_id' => $request->school_class_id,
                    'academic_year' => $activeSession->id,
                    'roll_number' => $request->roll_number,
                    'status' => 'active'
                ]);
            }

            return $this->successResponse($student->load('currentRecord'), 'Student profile updated successfully');
        });
    }

    /**
     * Replace the student's profile photo. Separate from update() so the
     * file upload path doesn't drag along all the validation rules of
     * the full profile form — admins want to swap a blurry photo without
     * having to refill the entire edit screen.
     *
     * Auth: admin, or the class teacher of the student's current class.
     * Subject teachers are deliberately NOT allowed — photo management
     * is administrative, not pedagogical.
     *
     * File: max 5 MB, image only. The uploaded object goes to the same
     * S3 prefix as admission photos so a single S3 lifecycle policy can
     * handle both. We attempt a best-effort delete of the previous photo
     * object to avoid leaking storage; failures are logged but never
     * block the update.
     */
    public function updatePhoto(Request $request, $id)
    {
        $user = $request->user();

        $student = Student::where('school_id', $user->school_id)->findOrFail($id);

        // RBAC: school admin OR the class teacher of this student's
        // current section. Mirrors who can touch students.update().
        $isAdmin = method_exists($user, 'isAdmin') ? $user->isAdmin() : false;
        $isClassTeacher = false;
        if (!$isAdmin) {
            $currentRecord = $student->currentRecord;
            if ($currentRecord && $currentRecord->school_class_id) {
                $cls = \App\Models\SchoolClass::find($currentRecord->school_class_id);
                $isClassTeacher = $cls && (int) $cls->class_teacher_id === (int) $user->id;
            }
        }
        if (!$isAdmin && !$isClassTeacher) {
            return $this->errorResponse(
                'Only the school administrator or this student\'s class teacher can change the profile photo.',
                403
            );
        }

        $request->validate([
            // 5 MB cap. image rule rejects non-image MIME types AND
            // non-images that pretend to be images via extension.
            'photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        try {
            // Same S3 prefix shape AdmissionController uses for admission
            // photos, just under /students/ instead of /admissions/.
            $path = $request->file('photo')->store(
                'school-' . $student->school_id . '/students/photos',
                's3'
            );

            if (!$path) {
                return $this->errorResponse('Could not upload the photo. Please try again.', 500);
            }

            $url = \Storage::disk('s3')->url($path);
            $oldUrl = $student->photo_path;

            $student->update(['photo_path' => $url]);

            // Best-effort cleanup of the previous object. We don't want
            // to delete the previous file UNTIL the new one is safely
            // stored AND the DB is updated — that way an S3 failure
            // can't strand the student with no photo. Pull the key out
            // of the URL and forget it from the disk. Any error here
            // is non-fatal — it just leaks one orphaned S3 object.
            if ($oldUrl) {
                try {
                    $oldKey = $this->s3KeyFromUrl($oldUrl);
                    if ($oldKey && $oldKey !== $path) {
                        \Storage::disk('s3')->delete($oldKey);
                    }
                } catch (\Throwable $e) {
                    \Log::warning('Failed to delete old student photo from S3', [
                        'student_id' => $student->id,
                        'old_url'    => $oldUrl,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }

            return $this->successResponse(
                ['photo_path' => $url, 'student_id' => $student->id],
                'Profile photo updated.'
            );
        } catch (\Throwable $e) {
            \Log::error('Student photo update failed', [
                'student_id' => $student->id,
                'error'      => $e->getMessage(),
            ]);
            return $this->errorResponse('Could not update the profile photo. Please try again.', 500);
        }
    }

    /**
     * Extract the S3 key from a public S3 URL we generated via
     * Storage::disk('s3')->url($path). The URL shape depends on the
     * driver config (path-style vs virtual-hosted), so we strip from
     * the bucket name onward. Returns null if we can't recognise the
     * shape — callers must treat null as "skip cleanup".
     */
    private function s3KeyFromUrl(?string $url): ?string
    {
        if (!$url) return null;
        // Anything after "school-" is the key we stored under. This
        // matches every photo we've ever written (admissions + students),
        // and is robust to bucket-name / region changes.
        $marker = 'school-';
        $pos = strpos($url, $marker);
        return $pos === false ? null : substr($url, $pos);
    }

    /**
     * Lightweight performance snapshot for the student-app dashboard.
     *
     * Returns just two numbers — overall academic score % and current-
     * session attendance % — that drive the two circular progress
     * indicators on the home screen. Used to be hardcoded as 85 and 92
     * literals in the React Native code; this endpoint replaces them.
     *
     * Built as a separate, narrow endpoint instead of reusing the
     * existing /students/results + /students/attendance/report:
     *
     *   - /students/results returns the full grouped-by-exam payload
     *     PLUS runs a live "class average" query per subject, so it
     *     scales as O(student_subjects). Way too heavy to call on every
     *     dashboard mount.
     *   - /students/attendance/report returns the entire record list
     *     for the year (used by the Attendance screen). Same problem.
     *
     * This endpoint loads ONLY the aggregate columns it needs (sum +
     * count) with a single pass over each table, so it's safe to call
     * on every dashboard load and on pull-to-refresh.
     */
    public function performance(Request $request)
    {
        $login = $request->user();
        $student = $login->student;

        if (!$student) {
            return $this->errorResponse('Student record not found', 404);
        }

        $school = $student->school;
        $activeSession = $school?->getActiveSession();
        $sessionId = $activeSession?->id;

        // ---- Overall score (published marks, current session only) -----
        // Sum of total_obtained / sum of total possible marks across all
        // published exam structures for this student in the active term.
        //
        // Using a single SELECT with a subquery for max_marks per
        // structure avoids the N+1 component->sum we'd get if we
        // loaded the relationship the way /students/results does.
        $marks = \DB::table('student_exam_marks')
            ->join('exam_structures', 'student_exam_marks.exam_structure_id', '=', 'exam_structures.id')
            ->leftJoin('exam_terms', 'exam_structures.exam_term_id', '=', 'exam_terms.id')
            ->where('student_exam_marks.student_id', $student->id)
            ->where('exam_structures.is_published', true)
            ->when($sessionId, fn($q) => $q->where('exam_terms.session_id', $sessionId))
            ->select('exam_structures.id as structure_id', 'student_exam_marks.total_obtained')
            ->get();

        $structureIds = $marks->pluck('structure_id')->unique()->all();
        $maxPerStructure = \DB::table('exam_structure_components')
            ->whereIn('exam_structure_id', $structureIds)
            ->select('exam_structure_id', \DB::raw('SUM(max_marks) as total_max'))
            ->groupBy('exam_structure_id')
            ->get()
            ->keyBy('exam_structure_id');

        $sumObtained = 0.0;
        $sumMax = 0.0;
        foreach ($marks as $m) {
            $max = (float) ($maxPerStructure[$m->structure_id]->total_max ?? 0);
            if ($max <= 0) continue;
            $sumObtained += (float) $m->total_obtained;
            $sumMax += $max;
        }
        $overallScore = $sumMax > 0 ? (int) round(($sumObtained / $sumMax) * 100) : 0;
        $publishedExamCount = $marks->pluck('structure_id')->unique()->count();

        // ---- Attendance percent (current session) ----------------------
        // Same formula as StudentAttendanceController::generateReportData
        // so the dashboard ring and the attendance screen agree.
        //
        // (present + late + 0.5 * half_day) / total_days
        //
        // Scoped to the active session's date range when available; falls
        // back to all-time records otherwise (which matches what the
        // attendance report screen shows today).
        $attendanceQuery = \DB::table('student_attendances')
            ->where('student_id', $student->id)
            ->where('school_id', $student->school_id);

        if ($activeSession && $activeSession->start_date && $activeSession->end_date) {
            $attendanceQuery->whereBetween('date', [$activeSession->start_date, $activeSession->end_date]);
        }

        $attCounts = $attendanceQuery
            ->selectRaw("
                COUNT(*) as total_days,
                SUM(CASE WHEN status='present'  THEN 1 ELSE 0 END) as present_days,
                SUM(CASE WHEN status='late'     THEN 1 ELSE 0 END) as late_days,
                SUM(CASE WHEN status='half_day' THEN 1 ELSE 0 END) as half_days,
                SUM(CASE WHEN status='absent'   THEN 1 ELSE 0 END) as absent_days
            ")
            ->first();

        $totalDays    = (int) ($attCounts->total_days ?? 0);
        $presentDays  = (int) ($attCounts->present_days ?? 0);
        $lateDays     = (int) ($attCounts->late_days ?? 0);
        $halfDays     = (int) ($attCounts->half_days ?? 0);
        $absentDays   = (int) ($attCounts->absent_days ?? 0);

        $weight = $presentDays + $lateDays + ($halfDays * 0.5);
        $attendancePercent = $totalDays > 0 ? (int) round(($weight / $totalDays) * 100) : 0;

        return $this->successResponse([
            'overall_score'      => $overallScore,
            'attendance_percent' => $attendancePercent,
            // Side data the dashboard can use for the "View Detailed Report"
            // affordance and to hide the card entirely on day-one accounts
            // who have zero data yet.
            'has_data' => [
                'exams'      => $publishedExamCount > 0,
                'attendance' => $totalDays > 0,
            ],
            'counts' => [
                'published_exams'    => $publishedExamCount,
                'attendance_total'   => $totalDays,
                'attendance_present' => $presentDays,
                'attendance_late'    => $lateDays,
                'attendance_half'    => $halfDays,
                'attendance_absent'  => $absentDays,
            ],
            'session' => $activeSession ? ['id' => $activeSession->id, 'name' => $activeSession->name] : null,
        ], 'Performance snapshot retrieved');
    }

    /**
     * Student-facing homework list — the homework currently assigned
     * to the class the student is enrolled in for the active session.
     *
     * Returns the most recent first, capped at 50 items so a long-
     * running class doesn't blow up the response. Pagination would be
     * over-engineering for a single screen; we sort by due_date desc
     * and let the UI scroll.
     */
    public function homework(Request $request)
    {
        $login = $request->user();
        $student = $login->student;
        if (!$student) {
            return $this->errorResponse('Student record not found', 404);
        }

        $school = $student->school;
        $activeSession = $school?->getActiveSession();
        if (!$activeSession) {
            return $this->successResponse(['homework' => []]);
        }

        $currentRecord = $student->academicRecords()
            ->where('academic_year', $activeSession->id)
            ->first();
        if (!$currentRecord || !$currentRecord->school_class_id) {
            return $this->successResponse(['homework' => []]);
        }

        // Fetch every active row for this class — homework AND
        // assignment kinds. The student app splits them into two
        // tabs client-side; the parent app shows them together. We
        // include the current student's own submission (if any) so
        // the UI can show "Submitted ✓ / Pending" without a second
        // round trip per assignment.
        $homeworks = \App\Models\Homework::where('school_id', $student->school_id)
            ->where('school_class_id', $currentRecord->school_class_id)
            ->where('status', 'active')
            ->with([
                'subject:id,name',
                'creator:id,name',
                // Eager-load ONLY this student's submission per row,
                // not all submissions, to keep the payload tight.
                'submissions' => function ($q) use ($student) {
                    $q->where('student_id', $student->id);
                },
            ])
            // Sort newest first using whichever date the row carries
            // (assignments use due_date, homework uses for_date).
            ->orderByRaw('COALESCE(due_date, for_date, created_at) DESC')
            ->limit(100)
            ->get();

        $rows = $homeworks->map(function ($h) {
            // Pull this student's submission (if any) from the
            // eager-loaded relation. Nested relation is already
            // scoped to student_id above, so it's at most one row.
            $sub = $h->submissions->first();

            $kind     = $h->kind ?? 'homework';
            $dueDate  = $h->due_date ? $h->due_date->toDateString() : null;
            $forDate  = $h->for_date ? $h->for_date->toDateString() : null;

            return [
                'id'           => $h->id,
                'kind'         => $kind,
                'title'        => $h->title,
                'description'  => $h->description,
                'for_date'     => $forDate,
                'due_date'     => $dueDate,
                // Both `subject_id` AND the resolved name. The id lets
                // the subject-detail screen filter the feed to "only
                // items tagged to THIS subject" without a second
                // network round trip; the name is kept for the
                // homework/assignment list views that show all subjects.
                'subject_id'   => $h->subject_id,
                'subject'      => $h->subject?->name,
                'teacher_name' => $h->creator?->name,
                'is_overdue'   => $dueDate && \Carbon\Carbon::parse($dueDate)->isPast(),
                // Submission summary — only filled when an assignment
                // has a submission. UI uses this to drive the
                // Submitted / Pending pill + the "View Submission" link.
                'submission'   => $sub ? [
                    'id'           => $sub->id,
                    'file_url'     => $sub->file_url,
                    'file_name'    => $sub->file_name,
                    'submitted_at' => $sub->submitted_at?->toIso8601String(),
                    'marks'        => $sub->marks,
                    'feedback'     => $sub->feedback,
                    'graded_at'    => $sub->graded_at?->toIso8601String(),
                ] : null,
                'created_at'   => $h->created_at?->toIso8601String(),
            ];
        });

        return $this->successResponse(['homework' => $rows]);
    }

    public function getRoster($classId, Request $request)
    {
        $school = $request->user()->school;
        $activeSession = $school->getActiveSession();
        $sessionId = $activeSession->id;

        $students = Student::where('school_id', $school->id)
            ->whereHas('currentRecord', function ($q) use ($classId, $sessionId) {
                $q->where('school_class_id', $classId)
                    ->where('academic_year', $sessionId);
            })
            ->with([
                'currentRecord' => function ($q) use ($sessionId) {
                    $q->where('academic_year', $sessionId);
                }
            ])
            ->get()
            ->sortBy(function ($student) {
                return (int) $student->currentRecord->roll_number;
            })
            ->values();

        return $this->successResponse($students, 'Student roster retrieved successfully');
    }

    public function studentLogin(Request $request)
    {
        $request->validate([
            'school_id' => 'required|exists:schools,id',
            'school_slug' => 'required|exists:schools,slug',
            'admission_id' => 'required|exists:students,admission_number',
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $school = School::where('id', $request->school_id)->where('slug', $request->school_slug)->first();
        if (!$school) {
            return $this->errorResponse('School not found', 404);
        }

        // Suspension gate: students never log in to a suspended school. There's
        // no "I need to renew" UI on the student side, so unlike the admin
        // login path there's no reason to let students through.
        if ($school->subscription_status === 'suspended') {
            return $this->errorResponse(
                'This school is temporarily suspended. Please contact your school administrator.',
                403
            );
        }

        $student = Student::where('school_id', $school->id)->where('admission_number', $request->admission_id)->first();
        if (!$student) {
            return $this->errorResponse('Student not found', 404);
        }

        $login = StudentLogin::where('student_id', $student->id)->first();
        if (!$login) {
            return $this->errorResponse('Login not found', 404);
        }

        if (!Hash::check($request->password, $login->password)) {
            return $this->errorResponse('Invalid password', 401);
        }

        $token = $login->createToken('student-token')->plainTextToken;

        return $this->successResponse([
            'token' => $token,
            'student' => $student,
            // 'school' => $school,
        ]);
    }
    public function profile(Request $request)
    {
        $login = $request->user();

        if (!$login || !$login->student) {
            return $this->errorResponse('Student record not found', 404);
        }

        $student = $login->student;
        $school = $student->school;
        
        // Use the official school method to get the active session ID
        $activeSession = $school->getActiveSession();
        $sessionId = $activeSession ? $activeSession->id : null;

        // Load with official session filtering
        $student->load([
            'currentRecord' => function($query) use ($sessionId) {
                if ($sessionId) {
                    $query->where('academic_year', $sessionId);
                }
            },
            'currentRecord.schoolClass.grade',
            'currentRecord.schoolClass.section',
            'school:id,name,slug,logo_path,current_session',
            // Documents are surfaced by the parent + student mobile apps under
            // /(dashboard)/documents — eager load them with their type so the
            // mobile UI renders the verified collection list.
            'documents.type',
        ]);

        return $this->successResponse([
            'student' => $student,
            'login' => [
                'id' => $login->id,
                'email' => $login->email,
                'username' => $login->username ?? $login->email
            ],
            // Effective module set for this student's school. Mobile apps
            // (student + parent — same endpoint serves both after parent
            // profile-switch) cache this and use it to filter their
            // favourites grid / tab bar. Loaded from the per-school
            // cached bitmap so this adds ~0ms — no JOINs, no new queries.
            'enabled_modules' => \App\Services\ModuleAccessService::for($school)->all(),
        ]);
    }

    public function getFeeLedger(Request $request)
    {
        $login = $request->user();
        $student = $login->student;

        if (!$student) {
            return $this->errorResponse('Student record not found', 404);
        }

        $schoolId = $student->school_id;
        $activeSession = \App\Models\Session::where('school_id', $schoolId)->where('is_active', true)->first();

        if ($activeSession && !isset($activeSession->start_date)) {
             $activeSession = \App\Models\Session::find($activeSession->id);
        }

        // Calculate how many months have passed in the session
        $monthsPassed = 0;
        if ($activeSession && $activeSession->start_date) {
            $startDate = \Carbon\Carbon::parse($activeSession->start_date);
            $monthsPassed = $startDate->diffInMonths(now());
        }

        // 1. Fetch current academic record
        $currentRecord = $student->academicRecords()
            ->where('academic_year', $activeSession->id ?? 0)
            ->with(['schoolClass.grade'])
            ->first();

        $classId = $currentRecord?->school_class_id;
        $gradeId = $currentRecord?->schoolClass?->grade_id;

        // 2. Fetch all assignments applicable to this student/grade/class
        $assignments = \App\Models\FeeAssignment::with(['feeType', 'payments' => function($q) use ($student) {
            $q->where('student_id', $student->id);
        }])
        ->where('school_id', $schoolId)
        ->where('session_id', $activeSession->id ?? 0)
        ->where(function($q) use ($student, $classId, $gradeId) {
            $q->where('student_id', $student->id)
              ->orWhere(function($sq) {
                  $sq->whereNull('student_id')->whereNull('grade_id')->whereNull('class_id');
              });
            if ($classId) $q->orWhere('class_id', $classId);
            if ($gradeId) $q->orWhere('grade_id', $gradeId);
        })
        ->get();

        // 2.5 Lifetime Payment Check for One-Time Fees
        // Get all historical payments for this student to check for one-time fee coverage
        $historicalPayments = \App\Models\FeePayment::where('student_id', $student->id)
            ->where('status', 'paid')
            ->whereHas('assignment', function($q) {
                $q->whereHas('feeType', function($sq) {
                    $sq->where('frequency_type', 'one_time');
                });
            })
            ->with('assignment.feeType')
            ->get();
            
        $paidOneTimeFeeTypeIds = $historicalPayments->pluck('assignment.fee_type_id')->unique()->toArray();

        $processedAssignments = $assignments->map(function($a) use ($monthsPassed, $paidOneTimeFeeTypeIds) {
            $meta = $this->calculateInstallmentMeta($a, $monthsPassed);
            
            // Handle One-Time fees already paid in previous sessions
            if ($a->feeType->frequency_type === 'one_time' && in_array($a->fee_type_id, $paidOneTimeFeeTypeIds)) {
                // Return null to signify this should be hidden from the ledger
                return null;
            }

            return [
                'id' => $a->id,
                'name' => $a->feeType->name,
                'description' => $a->feeType->description,
                'total_amount' => $meta['total_amount'],
                'installment_amount' => $meta['installment_amount'],
                'paid_amount' => $meta['paid_amount'],
                'due_amount' => $meta['due_amount'],
                'current_installment_due' => $meta['current_installment_due'],
                'waived_amount' => $meta['waived_amount'],
                'status' => $meta['status'],
                'is_installment_paid' => $meta['is_installment_paid'],
                'pending_months_count' => $meta['pending_months_count'],
                'paid_months_count' => $meta['paid_months_count'],
                'due_day' => $a->due_day,
                'frequency' => $a->feeType->frequency_type,
            ];
        })->filter()->values(); // Filter out the nulls and reset indices


        // 3. Transactions History (LIFETIME - All sessions)
        $transactions = \App\Models\PaymentTransaction::whereHas('receipt', function($q) use ($student) {
            $q->where('student_id', $student->id);
        })
        ->with(['receipt.assignment.feeType'])
        ->orderBy('payment_date', 'desc')
        ->get()
        ->map(function($tx) {
            return [
                'id' => $tx->id,
                'fee_type' => $tx->receipt->assignment->feeType->name ?? 'Fee Payment',
                'amount' => (float)$tx->amount,
                'date' => $tx->payment_date ? $tx->payment_date->format('Y-m-d') : 'N/A',
                'method' => $tx->method,
                'receipt_no' => $tx->receipt->receipt_no ?? 'N/A'
            ];
        });

        return $this->successResponse([
            'summary' => [
                'total_fees' => $processedAssignments->sum('total_amount'),
                'total_paid' => $processedAssignments->sum('paid_amount'),
                'total_due' => $processedAssignments->sum('due_amount'),
                'total_waived' => $processedAssignments->sum('waived_amount'),
            ],
            'assignments' => $processedAssignments,
            'history' => $transactions
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return $this->successResponse(null, 'Logged out successfully');
    }

    public function requestPasswordReset(Request $request)
    {
        $request->validate([
            'school_id' => 'required|exists:schools,id',
            'admission_id' => 'required',
            'email' => 'required|email',
        ]);

        $student = Student::where('school_id', $request->school_id)
            ->where('admission_number', $request->admission_id)
            ->where('email', $request->email)
            ->first();

        if (!$student) {
            return $this->errorResponse('Student matching these details not found', 404);
        }

        // Generate 6-digit OTP
        // $otp = rand(100000, 999999);
        $otp="123456"; // remove for production
        // Store OTP
        StudentPasswordReset::updateOrCreate(
            ['email' => $request->email, 'school_id' => $request->school_id],
            [
                'otp' => Hash::make($otp),
                'token' => null, // Clear any old token
                'expires_at' => now()->addMinutes(10)
            ]
        );

        // Send Email
        try {
            Mail::to($request->email)->send(new StudentPasswordResetMail($otp, $student->name));
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to send OTP email. Please try again later.', 500);
        }

        return $this->successResponse(null, 'OTP sent successfully to your registered email');
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'school_id' => 'required|exists:schools,id',
            'email' => 'required|email',
            'otp' => 'required|string|size:6',
        ]);

        $reset = StudentPasswordReset::where('school_id', $request->school_id)
            ->where('email', $request->email)
            ->first();

        // Single error wording across "no pending reset", "wrong OTP",
        // "expired" — avoids leaking which case matched. The `!$reset->otp`
        // guard handles the post-verify state where otp was nulled (so
        // a re-submit of the same code doesn't pass on a half-burned row).
        if (
            !$reset
            || !$reset->otp
            || !Hash::check($request->otp, $reset->otp)
            || $reset->expires_at->isPast()
        ) {
            return $this->errorResponse(
                'The code you entered is incorrect or has expired. Please try again.',
                422
            );
        }

        // Generate temporary reset token
        $token = Str::random(64);
        $reset->update([
            'otp' => null,
            'token' => Hash::make($token),
            'expires_at' => now()->addMinutes(15) // Token valid for 15 mins
        ]);

        return $this->successResponse(['reset_token' => $token], 'OTP verified successfully');
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'school_id' => 'required|exists:schools,id',
            'email' => 'required|email',
            'reset_token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $reset = StudentPasswordReset::where('school_id', $request->school_id)
            ->where('email', $request->email)
            ->first();

        if (!$reset || !$reset->token || !Hash::check($request->reset_token, $reset->token) || $reset->expires_at->isPast()) {
            return $this->errorResponse(
                'Your reset session has expired. Please start over from "Forgot password".',
                422
            );
        }

        $student = \App\Models\Student::where('school_id', $request->school_id)
            ->where('email', $request->email)
            ->first();

        if (!$student) {
            // The account was deleted between OTP verify and now. Burn
            // the reset row so the token can't be retried on a re-created
            // account later.
            $reset->delete();
            return $this->errorResponse('This student account no longer exists.', 404);
        }

        // Provision a login row if missing.
        // Historical data integrity gap: many older imports created
        // `students` rows without a matching `student_logins` row, so
        // $student->login was NULL and `update()` blew up with
        // "Call to a member function update() on null". The user has
        // already proven email ownership via OTP, so it's safe to create
        // the login here with their chosen password.
        //
        // The `password => 'hashed'` cast on StudentLogin automatically
        // hashes plaintext values — passing Hash::make(...) explicitly
        // would double-hash, so we hand the raw password to the cast and
        // let Laravel format it. This also guarantees the stored value
        // is bcrypt-formatted, which sidesteps the "This password does
        // not use the Bcrypt algorithm" error some legacy plaintext rows
        // were producing on Hash::check during login.
        try {
            $login = $student->login()->firstOrNew(['student_id' => $student->id]);
            $login->fill([
                'school_id'        => $student->school_id,
                'student_id'       => $student->id,
                'admission_number' => $student->admission_number,
                'email'            => $student->email,
                'password'         => $request->password, // 'hashed' cast formats it
            ])->save();
        } catch (\Throwable $e) {
            \Log::error('Student password reset failed during login provision', [
                'student_id' => $student->id,
                'error'      => $e->getMessage(),
            ]);
            return $this->errorResponse(
                'Could not update your password. Please contact your school administrator.',
                500
            );
        }

        // Clean up reset record.
        $reset->delete();

        // Belt-and-braces: revoke any existing Sanctum tokens so a
        // stolen / forgotten device session is also kicked out by the
        // password change.
        try {
            $login->tokens()->delete();
        } catch (\Throwable $e) {
            // Non-fatal.
        }

        return $this->successResponse(null, 'Password reset successfully. You can now login with your new password.');
    }

    public function getSubjects(Request $request)
    {
        $login = $request->user();
        $student = $login->student;

        if (!$student) {
            return $this->errorResponse('Student record not found', 404);
        }

        $activeSession = $student->school->getActiveSession();

        $currentRecord = $student->academicRecords()
            ->where('academic_year', $activeSession->id)
            ->with(['schoolClass.subjects' => function ($q) {
                // SchoolClass::subjects already declares lesson_plan in
                // withPivot(...), so loading the relationship is enough
                // to bring `pivot.lesson_plan` along — no extra query
                // needed for that one.
                $q->orderBy('subjects.name');
            }, 'schoolClass.grade:id,name', 'schoolClass.section:id,name'])
            ->first();

        if (!$currentRecord || !$currentRecord->schoolClass) {
            return $this->errorResponse('Active academic record or class not found for current session', 404);
        }

        $cls = $currentRecord->schoolClass;
        $subjects = $cls->subjects;

        // The Lesson Plan, Syllabus chapters and Subject Notes are all
        // teacher-authored content from the school admin's Class Subject
        // Studio. We expose them read-only here so the student app can
        // render a "Subject Detail" view with the three tabs that mirror
        // the admin side. Pulled in bulk with two queries (notes +
        // syllabus) keyed by pivot.id, the same pattern getData uses to
        // avoid N+1.
        $pivotIds = $subjects->pluck('pivot.id')->filter()->all();

        $notesByPivot = \DB::table('class_subject_notes')
            ->whereIn('class_subject_id', $pivotIds)
            ->orderBy('created_at')
            ->get()
            ->groupBy('class_subject_id');

        $syllabusByPivot = \DB::table('class_subject_syllabus')
            ->whereIn('class_subject_id', $pivotIds)
            ->orderBy('id')
            ->get()
            ->groupBy('class_subject_id');

        // Teacher names — small lookup so the UI can show "Taught by …"
        // without exposing the full users table.
        $teacherIds = $subjects->pluck('pivot.teacher_id')->filter()->unique()->all();
        $teachers = \DB::table('users')
            ->whereIn('id', $teacherIds)
            ->get(['id', 'name'])
            ->keyBy('id');

        $payload = $subjects->map(function ($sub) use ($notesByPivot, $syllabusByPivot, $teachers) {
            $pivotId = $sub->pivot->id ?? null;
            $teacherId = $sub->pivot->teacher_id ?? null;

            return [
                'id' => $sub->id,
                'name' => $sub->name,
                'code' => $sub->code,
                'pivot' => [
                    'id' => $pivotId,
                    'periods_per_week' => $sub->pivot->periods_per_week ?? null,
                    'teacher_id' => $teacherId,
                    'teacher_name' => $teacherId && isset($teachers[$teacherId]) ? $teachers[$teacherId]->name : null,
                    'lesson_plan' => $sub->pivot->lesson_plan ?? null,
                    'syllabus' => $pivotId && isset($syllabusByPivot[$pivotId])
                        ? $syllabusByPivot[$pivotId]->map(fn($s) => [
                            'id' => $s->id,
                            'topic' => $s->topic,
                            'description' => $s->description,
                            'status' => $s->status,
                        ])->values()
                        : [],
                    'notes' => $pivotId && isset($notesByPivot[$pivotId])
                        ? $notesByPivot[$pivotId]->map(fn($n) => [
                            'id' => $n->id,
                            'title' => $n->title,
                            'description' => $n->description,
                            'file_url' => $n->file_url,
                            'created_at' => $n->created_at,
                        ])->values()
                        : [],
                ],
            ];
        })->values();

        return $this->successResponse([
            // Wrapped in an object so we can also surface the real
            // session + class names — the old student app hardcoded
            // "Academic Year 2024-25" and "Curriculum v1.0", which
            // silently lied once the school rolled over. Now the UI
            // can read these dynamically.
            'subjects' => $payload,
            'session' => $activeSession ? [
                'id' => $activeSession->id,
                'name' => $activeSession->name,
            ] : null,
            'class' => [
                'id' => $cls->id,
                'grade' => $cls->grade?->name,
                'section' => $cls->section?->name,
                'full_name' => trim(($cls->grade?->name ?? '') . ' ' . ($cls->section?->name ?? '')),
            ],
        ], 'Subjects retrieved successfully');
    }

    public function getTimetable(Request $request)
    {
        $login = $request->user();
        $student = $login->student;
        if (!$student) {
            return $this->errorResponse('Student record not found', 404);
        }

        $activeSession = $student->school->getActiveSession();

        $currentRecord = $student->academicRecords()
            ->where('academic_year', $activeSession->id)
            ->first();

            if (!$currentRecord || !$currentRecord->school_class_id) {
            return $this->errorResponse('Active academic record or class not found for current session', 404);
        }

        $classId = $currentRecord->school_class_id;
        
        // Relationship helpers
        $with = ['subject', 'teacher:id,name', 'classroom'];

        // 1. Check for range-based fetching (new)
        if ($request->has('start_date')) {
            $startDate = $request->start_date;
            $endDate = $request->end_date ?? $startDate;

            $entries = \App\Models\TimetableEntry::where('school_class_id', $classId)
                ->whereBetween('date', [$startDate, $endDate])
                ->where('is_active', true)
                ->with($with)
                ->orderBy('date')
                ->orderBy('start_time')
                ->get();

            return $this->successResponse($entries, 'Timetable range retrieved successfully');
        }

        // 2. Check for single date (backward compatibility)
        $date = $request->query('date');
        if ($date) {
            $entries = \App\Models\TimetableEntry::where('school_class_id', $classId)
                ->where('date', $date)
                ->where('is_active', true)
                ->with($with)
                ->orderBy('start_time')
                ->get();
            return $this->successResponse([
                'date' => $date,
                'day_of_week' => strtolower(date('l', strtotime($date))),
                'entries' => $entries
            ], 'Daily timetable retrieved successfully');
        }

        // 3. Fallback: Full weekly schedule for the CURRENT WEEK (Date-specific entries only)
        $today = \Carbon\Carbon::today();
        $startOfWeek = $today->copy()->startOfWeek(); // Monday
        $endOfWeek = $today->copy()->endOfWeek(); // Sunday

        $allEntries = \App\Models\TimetableEntry::where('school_class_id', $classId)
            ->whereBetween('date', [$startOfWeek->toDateString(), $endOfWeek->toDateString()])
            ->where('is_active', true)
            ->with($with)
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        // Group by day of week name
        $grouped = $allEntries->groupBy(function ($item) {
            return strtolower(date('l', strtotime($item->date)));
        });

        // Ensure all days are present in the response
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $schedule = [];
        foreach ($days as $day) {
            $schedule[$day] = $grouped->get($day, []);
        }

        return $this->successResponse([
            'current_day' => strtolower(date('l')),
            'week_range' => [
                'start' => $startOfWeek->toDateString(),
                'end' => $endOfWeek->toDateString()
            ],
            'schedule' => $schedule
        ], 'Weekly timetable retrieved successfully');
    }
    public function updateDeviceToken(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $login = $request->user();

        \App\Models\StudentDeviceToken::updateOrCreate(
            ['token' => $request->token],
            ['student_id' => $login->student_id, 'updated_at' => now()]
        );

        return $this->successResponse(null, 'Device token updated successfully');
    }

    public function getNotificationStats(Request $request)
    {
        $login = $request->user();
        $student = $login->student;

        if (!$student) {
            return $this->errorResponse('Student record not found', 404);
        }

        $activeSession = $student->school->getActiveSession();

        // Get current class
        $currentRecord = $student->academicRecords()
            ->where('academic_year', $activeSession->id)
            ->first();

        $classId = $currentRecord ? $currentRecord->school_class_id : null;
        $lastReadAt = $login->last_read_circular_at;

        $query = \App\Models\Circular::where('school_id', $student->school_id)
            ->where('is_active', true)
            ->where('published_at', '<=', now())
            ->where(function ($q) use ($classId, $student) {
                $q->where('scope', 'school')
                    ->orWhere(function ($sq) use ($classId) {
                        $sq->where('scope', 'class')->where('school_class_id', $classId);
                    })
                    ->orWhere(function ($sq) use ($student) {
                        $sq->where('scope', 'student')->where('student_id', $student->id);
                    });
            });

        if ($lastReadAt) {
            $query->where('published_at', '>', $lastReadAt);
        }

        $unreadCount = $query->count();

        return $this->successResponse([
            'unread_count' => $unreadCount,
            'last_read_at' => $lastReadAt
        ], 'Notification stats retrieved successfully');
    }

    public function markNotificationsAsRead(Request $request)
    {
        $login = $request->user();
        $login->update([
            'last_read_circular_at' => now()
        ]);

        return $this->successResponse(null, 'Notifications marked as read');
    }

    public function getTeacherProfile($id, Request $request)
    {
        $teacher = \App\Models\User::where('id', $id)->first();
        
        if (!$teacher) {
            return $this->errorResponse('Teacher record not found in the system.', 404);
        }

        $isTeacher = $teacher->whereHas('role_relation', function($q) {
            $q->where('slug', 'teacher');
        })->where('id', $id)->exists();

        // Relaxed check: if no slug, check if they have teacher-like details
        if (!$isTeacher && empty($teacher->teacher_details)) {
             // return $this->errorResponse('The requested user is not registered as a teacher.', 403);
        }

        $teacher->load(['managedClasses.grade', 'managedClasses.section']);

        // Process specializations into primary/secondary subjects
        $details = $teacher->teacher_details ?? [];
        $specializations = $details['specializations'] ?? [];
        
        $subjectIds = collect($specializations)->pluck('subject_id')->unique();
        $subjectsMap = \App\Models\Subject::whereIn('id', $subjectIds)->get()->keyBy('id');

        $primarySubjects = [];
        $secondarySubjects = [];

        foreach ($specializations as $spec) {
            $subId = $spec['subject_id'];
            $subjectName = $subjectsMap[$subId]->name ?? 'Unknown Content';
            
            if (($spec['type'] ?? '') === 'Primary Subject') {
                $primarySubjects[] = $subjectName;
            } else {
                $secondarySubjects[] = $subjectName;
            }
        }

        // Prepare response data
        $responseData = $teacher->toArray();
        $responseData['primary_subjects'] = array_unique($primarySubjects);
        $responseData['secondary_subjects'] = array_unique($secondarySubjects);
        
        // Ensure some fields are present even if null for UI stability
        $responseData['phone'] = $teacher->phone ?? 'Contact school admin';
        $responseData['bio'] = $teacher->bio ?? 'Qualified educator dedicated to student success.';
        $responseData['specialization'] = !empty($primarySubjects) ? implode(', ', $primarySubjects) : 'Teaching Faculty';

        return $this->successResponse($responseData, 'Teacher profile retrieved successfully');
    }

    public function getCalendarEvents(Request $request)
    {
        $login = $request->user();
        $student = $login->student;

        if (!$student) {
            return $this->errorResponse('Student record not found', 404);
        }

        // Get Month & Year from request or default to current
        $month = $request->query('month', date('n'));
        $year = $request->query('year', date('Y'));

        // Get student's class ID
        $currentRecord = $student->academicRecords()
            ->where('academic_year', $student->school->current_session)
            ->first();
        $classId = $currentRecord ? $currentRecord->school_class_id : null;

        // 1. Fetch School Events & Holidays
        $events = SchoolEvent::where('school_id', $student->school_id)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->where(function ($q) use ($classId) {
                $q->where('target_type', 'all')
                    ->orWhere(function ($sq) use ($classId) {
                        $sq->where('target_type', 'class')->where('school_class_id', $classId);
                    });
            })
            ->get()
            ->map(function ($event) {
                return [
                    'id' => 'event_' . $event->id,
                    'title' => $event->name,
                    'date' => $event->date,
                    'type' => $event->type, // holiday or event
                    'duration' => $event->duration,
                    'description' => $event->type === 'holiday' ? 'School Holiday' : 'Institutional Event',
                ];
            });

        // 2. Fetch PTM Circulars
        $ptmCirculars = Circular::where('school_id', $student->school_id)
            ->where('type', 'ptm')
            ->where('is_active', true)
            ->whereBetween('published_at', [$startDate->toDateTimeString(), $endDate->toDateTimeString()])
            ->where(function ($q) use ($classId, $student) {
                $q->where('scope', 'school')
                    ->orWhere(function ($sq) use ($classId) {
                        $sq->where('scope', 'class')->where('school_class_id', $classId);
                    })
                    ->orWhere(function ($sq) use ($student) {
                        $sq->where('scope', 'student')->where('student_id', $student->id);
                    });
            })
            ->get()
            ->map(function ($circular) {
                return [
                    'id' => 'ptm_' . $circular->id,
                    'title' => $circular->title,
                    'date' => $circular->published_at->toDateString(),
                    'type' => 'ptm',
                    'duration' => 'full',
                    'description' => strip_tags($circular->description),
                ];
            });

        // 3. Merge and Sort
        $calendarData = $events->concat($ptmCirculars)->sortBy('date')->values();

        // 4. Working Days Metadata
        $workingDays = is_string($student->school->working_days) 
            ? json_decode($student->school->working_days, true) 
            : $student->school->working_days;

        return $this->successResponse([
            'month' => (int)$month,
            'year' => (int)$year,
            'events' => $calendarData,
            'working_days' => $workingDays ?? ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
        ], 'Calendar events retrieved successfully');
    }

    public function getResults(Request $request)
    {
        $login = $request->user();
        $student = $login->student;

        if (!$student) {
            return $this->errorResponse('Student record not found', 404);
        }

        $school = $student->school;
        $sessionName = $school->current_session;
        
        // Find the actual session record to get the ID
        $sessionRecord = \App\Models\Session::where('name', $sessionName)->first();
        $sessionId = $sessionRecord ? $sessionRecord->id : $sessionName;

        $currentRecord = $student->academicRecords()
            ->where(function($q) use ($sessionId, $sessionName) {
                $q->where('academic_year', $sessionId)
                  ->orWhere('academic_year', $sessionName);
            })
            ->first();

        if (!$currentRecord) {
            return $this->successResponse([
                'exams' => [],
                'trends' => [],
                'message' => "Your academic record for session '{$sessionName}' is not yet configured."
            ], 'Profile not yet configured.');
        }

        $classId = $currentRecord->school_class_id;

        // Fetch all marks for this student in current session
        // ONLY fetch marks where the structure is marked as PUBLISHED
        $marks = \App\Models\StudentExamMark::where('student_id', $student->id)
            ->whereHas('examStructure', function($q) use ($sessionId) {
                $q->where('is_published', true) // <--- Only published results
                  ->whereHas('term', function($sq) use ($sessionId) {
                    $sq->where('session_id', $sessionId);
                });
            })
            ->with(['examStructure.type', 'examStructure.subject', 'examStructure.components', 'examStructure.term'])
            ->get();

        $academicService = new \App\Services\AcademicService();
        
        // Group by Exam Type name (e.g. Unit Test, Mid Term)
        $groupedExams = [];
        $examAverages = [];

        $examTypes = $marks->pluck('examStructure.type')->unique('id');

        foreach ($examTypes as $type) {
            $typeMarks = $marks->filter(function($m) use ($type) {
                return $m->examStructure->exam_type_id == $type->id;
            });

            // Group by Term for this Exam Type
            $termsForType = $typeMarks->pluck('examStructure.term')->unique('id');

            foreach ($termsForType as $term) {
                $termTypeMarks = $typeMarks->filter(function($m) use ($term) {
                    return $m->examStructure->exam_term_id == $term->id;
                });

                $subjectData = [];
                $totalObtainedInExam = 0;
                $totalMaxInExam = 0;

                foreach ($termTypeMarks as $mark) {
                    $structure = $mark->examStructure;
                    $subject = $structure->subject;
                    
                    $maxMarks = $structure->components->sum('max_marks');
                    $obtained = $mark->total_obtained;
                    $percentage = $maxMarks > 0 ? round(($obtained / $maxMarks) * 100, 1) : 0;

                    $totalObtainedInExam += $obtained;
                    $totalMaxInExam += $maxMarks;

                    // Calculate class average
                    $classAvg = \App\Models\StudentExamMark::where('exam_structure_id', $structure->id)
                        ->avg('total_obtained');
                    
                    $classAvgPercentage = $maxMarks > 0 ? round(($classAvg / $maxMarks) * 100, 1) : 0;

                    $subjectData[] = [
                        'id' => $mark->id,
                        'subject' => $subject->name,
                        'score' => $percentage,
                        'obtained' => $obtained,
                        'total' => $maxMarks,
                        'grade' => $mark->grade_obtained ?? 'N/A',
                        'rank' => '-',
                        'avg' => $classAvgPercentage,
                        'components' => $mark->component_marks
                    ];
                }

                if (!empty($subjectData)) {
                    $displayName = $term->name . ' - ' . $type->name;
                    $groupedExams[$displayName] = $subjectData;
                    $examAverages[] = [
                        'label' => $displayName,
                        'average' => $totalMaxInExam > 0 ? round(($totalObtainedInExam / $totalMaxInExam) * 100) : 0
                    ];
                }
            }
        }

        return $this->successResponse([
            'exams' => $groupedExams,
            'trends' => $examAverages,
            'summary' => [
                'student_name' => $student->name,
                'class_name' => $currentRecord->schoolClass->full_name ?? 'N/A',
                'session' => $school->currentSession?->name
            ]
        ], 'Results retrieved successfully');
    }
}

