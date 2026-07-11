<?php

namespace App\Http\Controllers;

use App\Mail\ParentLoginOtpMail;
use App\Models\CommonOtp;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentLogin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ParentAuthController extends Controller
{
    /**
     * Look up students this parent could log in as.
     *
     * Match rules (tightened from earlier wildcard LIKE):
     *   - students.parent_email = $email   (exact, case-insensitive)
     *   - students.email        = $email   (exact, case-insensitive)
     *
     * The previous `parent_email LIKE '%email%'` let "bob@x.com" match
     * "bob@x.com.attacker" and similar near-misses. We never want that on
     * an auth lookup.
     */
    private function studentsForParent(string $email)
    {
        $email = strtolower($email);
        return Student::withoutGlobalScopes()
            ->where(function ($q) use ($email) {
                $q->whereRaw('LOWER(parent_email) = ?', [$email])
                  ->orWhereRaw('LOWER(email) = ?', [$email]);
            });
    }

    public function sendOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $email = strtolower($request->email);

        // Always respond with the SAME generic message regardless of whether
        // the email matches a student. Returning a 404 ("no students found")
        // turned this endpoint into an account-enumeration oracle. We only
        // actually send the email when there's a real match.
        $genericResponse = $this->successResponse(null, 'If an account exists for this email, an OTP has been sent.');

        $hasStudent = $this->studentsForParent($email)->exists();
        if (!$hasStudent) {
            return $genericResponse;
        }

        $otp = rand(100000, 999999);

        CommonOtp::updateOrCreate(
            ['identifier' => $email, 'type' => 'parent_login'],
            [
                'otp' => Hash::make($otp),
                'expires_at' => now()->addMinutes(10),
            ]
        );

        // Resolve a school for the branded mail template. First matching student's
        // school is fine — the OTP is parent identity, not school-scoped.
        $schoolId = $this->studentsForParent($email)->value('school_id');
        $school = $schoolId ? School::find($schoolId) : null;

        try {
            Mail::to($email)->send(new ParentLoginOtpMail((string) $otp, $school, 10));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Parent OTP send failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            // Still return the generic response — don't leak that the address
            // is valid via a different error path.
            return $genericResponse;
        }

        return $genericResponse;
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required|string|size:6',
        ]);

        $email = strtolower($request->email);
        $otp = $request->otp;

        $otpRecord = CommonOtp::where('identifier', $email)
            ->where('type', 'parent_login')
            ->first();

        if (!$otpRecord || !Hash::check($otp, $otpRecord->otp) || $otpRecord->expires_at->isPast()) {
            return $this->errorResponse('Invalid or expired OTP', 422);
        }

        $students = $this->studentsForParent($email)
            ->with(['school', 'currentRecord.schoolClass.grade', 'currentRecord.schoolClass.section'])
            ->get();

        // Issue a short-lived server-side session token. The token only proves
        // the bearer completed the OTP step for THIS email. Both /login-as-student
        // and /students reject any call whose token doesn't resolve to the same
        // email server-side. Token lives in cache (file driver today, Redis later
        // — no code change needed when CACHE_DRIVER is flipped).
        $parentToken = Str::random(64);
        Cache::put('parent_session:' . $parentToken, $email, now()->addMinutes(30));

        // Clear OTP after successful verification so it can't be replayed.
        $otpRecord->delete();

        return $this->successResponse([
            'students' => $students,
            'email' => $email,
            'parent_token' => $parentToken,
        ]);
    }

    /**
     * Resolve and validate the parent_session token from the request.
     * Returns the verified email or null. The caller is expected to bail with
     * 401 on null.
     */
    private function emailFromParentToken(Request $request, string $expectedEmail): ?string
    {
        $token = (string) $request->input('parent_token', '');
        if ($token === '') return null;
        $cached = Cache::get('parent_session:' . $token);
        if (!$cached) return null;
        if (strtolower((string) $cached) !== strtolower($expectedEmail)) return null;
        return $cached;
    }

    public function loginAsStudent(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'student_id' => 'required|exists:students,id',
            'parent_token' => 'required|string',
        ]);

        // Re-enforced: a valid, unexpired parent_session must back this call,
        // and it must belong to the SAME email being claimed. Previously this
        // check was commented out, so anyone with an email + student id could
        // mint a Sanctum token as that student.
        $email = $this->emailFromParentToken($request, $request->email);
        if (!$email) {
            return $this->errorResponse('Unauthorized: please verify OTP again.', 401);
        }

        $student = $this->studentsForParent($email)
            ->where('id', $request->student_id)
            ->with('school:id,subscription_status,name')
            ->first();

        if (!$student) {
            return $this->errorResponse('Unauthorized: Student not associated with this parent email.', 403);
        }

        // Suspension gate: parents-as-student also blocked when school is
        // suspended. Same rationale as direct student login.
        if ($student->school && $student->school->subscription_status === 'suspended') {
            return $this->errorResponse(
                'This school is temporarily suspended. Please contact your school administrator.',
                403
            );
        }

        $login = StudentLogin::where('student_id', $student->id)->first();
        if (!$login) {
            return $this->errorResponse('Student login record not found.', 404);
        }

        $token = $login->createToken('student-token')->plainTextToken;

        return $this->successResponse([
            'token' => $token,
            'student' => $student->load(['currentRecord.schoolClass.grade', 'currentRecord.schoolClass.section', 'school']),
        ], 'Logged in as student successfully');
    }

    /**
     * Re-list the students for a verified parent session. The route is still
     * unauthenticated (Sanctum doesn't apply pre-OTP) but the body MUST carry
     * a parent_token matching the email server-side — otherwise any anonymous
     * caller could enumerate PII.
     */
    public function getStudents(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'parent_token' => 'required|string',
        ]);

        $email = $this->emailFromParentToken($request, $request->email);
        if (!$email) {
            return $this->errorResponse('Unauthorized: please verify OTP again.', 401);
        }

        $students = $this->studentsForParent($email)
            ->with(['school', 'currentRecord.schoolClass.grade', 'currentRecord.schoolClass.section'])
            ->get();

        return $this->successResponse([
            'students' => $students,
        ]);
    }
}
