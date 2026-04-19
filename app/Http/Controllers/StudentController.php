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

        return $this->successResponse($query->paginate(20));
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

            // Create Login
            $student->login()->create([
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

        $student = $login->student->load([
            'currentRecord.schoolClass.grade',
            'currentRecord.schoolClass.section',
            'school',
            'academicRecords',
            'documents.type',
            'feeAssignments.feeType',
            'feeAssignments.installments',
            'fines.feeType',
            'payments.transactions'
        ]);

        return $this->successResponse([
            'student' => $student,
            'login' => $login
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

        if (!$activeSession || !isset($activeSession->start_date)) {
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

        $processedAssignments = $assignments->map(function($a) use ($monthsPassed) {
            $meta = $this->calculateInstallmentMeta($a, $monthsPassed);

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
        });

        // 3. Transactions History
        $transactions = \App\Models\PaymentTransaction::whereHas('receipt', function($q) use ($student) {
            $q->where('student_id', $student->id);
        })
        ->with('receipt.assignment.feeType')
        ->orderBy('payment_date', 'desc')
        ->get()
        ->map(function($tx) {
            return [
                'id' => $tx->id,
                'fee_type' => $tx->receipt->assignment->feeType->name,
                'amount' => (float)$tx->amount,
                'date' => $tx->payment_date,
                'method' => $tx->method,
                'receipt_no' => $tx->receipt->receipt_no
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
        $otp = rand(100000, 999999);

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

        if (!$reset || !Hash::check($request->otp, $reset->otp) || $reset->expires_at->isPast()) {
            return $this->errorResponse('Invalid or expired OTP', 422);
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

        if (!$reset || !Hash::check($request->reset_token, $reset->token) || $reset->expires_at->isPast()) {
            return $this->errorResponse('Invalid or expired reset session', 422);
        }

        $student = Student::where('school_id', $request->school_id)
            ->where('email', $request->email)
            ->firstOrFail();

        // Update Password
        $student->login->update([
            'password' => Hash::make($request->password)
        ]);

        // Clean up reset record
        $reset->delete();

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
            ->with('schoolClass.subjects')
            ->first();

        if (!$currentRecord || !$currentRecord->schoolClass) {
            return $this->errorResponse('Active academic record or class not found for current session', 404);
        }

        return $this->successResponse($currentRecord->schoolClass->subjects, 'Subjects retrieved successfully');
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
            ['student_id' => $login->student_id, 'token' => $request->token],
            ['updated_at' => now()]
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

        try {
            $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();
        } catch (\Exception $e) {
            return $this->errorResponse('Invalid date provided', 422);
        }

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
}

