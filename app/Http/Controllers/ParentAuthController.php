<?php

namespace App\Http\Controllers;

use App\Mail\ParentLoginOtpMail;
use App\Models\CommonOtp;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentLogin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ParentAuthController extends Controller
{
    public function sendOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $email = strtolower($request->email);
        
        // Find associated students (either by parent_email or their own email as fallback)
        // Direct DB count to bypass any hidden filters
        $studentsCount = \DB::table('students')
            ->where('parent_email', 'like', '%' . $email . '%')
            ->orWhere('email', $email)
            ->count();

        if ($studentsCount === 0) {
            return $this->errorResponse('No students found associated with this email.', 404);
        }

        // Generate 6-digit OTP
        $otp = rand(100000, 999999);

        // Store OTP
        CommonOtp::updateOrCreate(
            ['identifier' => $email, 'type' => 'parent_login'],
            [
                'otp' => Hash::make($otp),
                'expires_at' => now()->addMinutes(10),
            ]
        );

        // Resolve the school this parent is linked to (for branded subject + sender).
        // We pick the first matching student's school; if the parent has children in
        // multiple schools we still use whichever is found first — the OTP itself is
        // the parent's identity, not school-scoped.
        $schoolId = \DB::table('students')
            ->where(function ($q) use ($email) {
                $q->where('parent_email', 'like', '%' . $email . '%')
                  ->orWhere('email', $email);
            })
            ->value('school_id');
        $school = $schoolId ? School::find($schoolId) : null;

        // Send Email using the branded ParentLoginOtpMail (per-school sender name).
        try {
            Mail::to($email)->send(new ParentLoginOtpMail((string) $otp, $school, 10));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Parent OTP send failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to send OTP email. Please try again later.', 500);
        }

        return $this->successResponse(null, 'OTP sent successfully to your email.');
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

        // OTP verified, find associated students
        // OTP verified, find associated students using direct query for maximum reliability
        $students = Student::withoutGlobalScopes()
            ->where('parent_email', 'like', '%' . $email . '%')
            ->orWhere('email', $email)
            ->with(['school', 'currentRecord.schoolClass.grade', 'currentRecord.schoolClass.section'])
            ->get();

        \Log::info('Parent Login Search', [
            'email' => $email,
            'count' => $students->count(),
            'ids' => $students->pluck('id')
        ]);

        // Clear OTP after successful verification
        $otpRecord->delete();

        return $this->successResponse([
            'students' => $students,
            'email' => $email,
            'parent_token' => Str::random(64), // Temporary token to authenticate the selection
        ]);
    }

    public function loginAsStudent(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'student_id' => 'required|exists:students,id',
            // 'parent_token' => 'required', // Optional: could verify the temporary token
        ]);

        $email = $request->email;
        $studentId = $request->student_id;

        // Verify the student belongs to this parent
        $student = Student::where('id', $studentId)
            ->where(function ($query) use ($email) {
                $query->where('parent_email', $email)
                    ->orWhere('email', $email);
            })
            ->first();

        if (!$student) {
            return $this->errorResponse('Unauthorized: Student not associated with this parent email.', 403);
        }

        $login = StudentLogin::where('student_id', $student->id)->first();
        if (!$login) {
            return $this->errorResponse('Student login record not found.', 404);
        }

        // Issue a standard student token
        $token = $login->createToken('student-token')->plainTextToken;

        return $this->successResponse([
            'token' => $token,
            'student' => $student->load(['currentRecord.schoolClass.grade', 'currentRecord.schoolClass.section', 'school']),
        ], 'Logged in as student successfully');
    }

    public function getStudents(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $email = strtolower($request->email);

        $students = Student::withoutGlobalScopes()
            ->where('parent_email', 'like', '%' . $email . '%')
            ->orWhere('email', $email)
            ->with(['school', 'currentRecord.schoolClass.grade', 'currentRecord.schoolClass.section'])
            ->get();

        return $this->successResponse([
            'students' => $students,
        ]);
    }
}
