<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ParentAuthController;
use App\Http\Controllers\SchoolController;
use App\Http\Controllers\TimetableController;
use App\Http\Controllers\SubstitutionController;
use App\Http\Controllers\AdmissionController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\InquiryController;
use App\Http\Controllers\LandingPageController;
use App\Http\Controllers\DocumentTypeController;
use App\Http\Controllers\EmailTemplateController;
use App\Http\Controllers\TimetableSchedulingController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\CircularController;
use App\Http\Controllers\StudentAttendanceController;
use App\Http\Controllers\Admin\FeePaymentController;
use App\Http\Controllers\Admin\FeeTypeController;
use App\Http\Controllers\Admin\FeeAssignmentController;
use App\Http\Controllers\Admin\FinanceReportController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\Admin\ExamConfigurationController;
use App\Http\Controllers\Teacher\MarkManagementController;
use App\Http\Controllers\Admin\AcademicPromotionController;
use App\Http\Controllers\API\OnlinePaymentController;
use App\Http\Controllers\Teacher\FineController;
use App\Http\Controllers\Teacher\DashboardController as TeacherDashboardController;
use App\Http\Controllers\TeacherAttendanceController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Teacher\HomeworkController as TeacherHomeworkController;

// Per-IP limits on unauthenticated endpoints (the global `api` limiter is
// 300/min — far too loose for logins, OTPs and public forms).
Route::middleware('throttle:5,1')->post('/register', [AuthController::class, 'register']);
Route::middleware('throttle:10,1')->post('/login', [AuthController::class, 'login']);

// Self-serve password reset for staff (teachers, admins, incharges).
// Same three-step OTP flow the student app uses, but operates on the
// `users` table via App\Http\Controllers\StaffPasswordController.
// Rate-limited because the OTP step sends real emails and the verify
// step is brute-force adjacent (6-digit code).
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/staff/forgot-password', [\App\Http\Controllers\StaffPasswordController::class, 'requestReset']);
    Route::post('/staff/verify-otp',       [\App\Http\Controllers\StaffPasswordController::class, 'verifyOtp']);
    Route::post('/staff/reset-password',   [\App\Http\Controllers\StaffPasswordController::class, 'reset']);
});

// Public Landing Pages & Inquiries
// Max 30 requests per minute per client IP (returns 429 after that).
Route::middleware('throttle:30,1')->get('/school/public', [SchoolController::class, 'getPublicSchoolInfo']);
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/schools/search', [SchoolController::class, 'searchSchools']);
    Route::get('/school/check-slug-availability', [SchoolController::class, 'checkPublicSlugAvailability']);
    Route::get('/school/public/cms', [LandingPageController::class, 'getCMSData']);
});

// Public forms: enquiry / admission (S3 photo upload) / demo request
// (emails the sales team) — stop spam floods.
Route::middleware('throttle:10,1')->post('/inquiries', [InquiryController::class, 'store']);
Route::middleware('throttle:5,1')->post('/demo-request', [InquiryController::class, 'storeDemoRequest']);
Route::middleware('throttle:10,1')->post('/admissions', [AdmissionController::class, 'store']);

// Impersonation handoff exchange — PUBLIC (no auth) but single-use and
// short-lived. The school frontend POSTs the handoff code it received via
// the platform-admin redirect and gets back the real Sanctum token.
// Throttle protects against brute-forcing the random handoff code.
Route::middleware('throttle:30,1')->post(
    '/impersonation/exchange',
    [\App\Http\Controllers\Platform\ImpersonationController::class, 'exchange']
);


// teacher and admin
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);

    // School-side plan/usage snapshot — feeds the usage banner and the
    // upgrade modal. Always returns the caller's school, no cross-tenant
    // hint accepted.
    Route::get('/school/usage', [\App\Http\Controllers\SchoolUsageController::class, 'show']);
    Route::post('/school/upgrade-request', [\App\Http\Controllers\SchoolUsageController::class, 'requestUpgrade']);

    // Module access — both endpoints are read-only and intentionally
    // outside the module middleware (you must always be able to
    // discover your own access level, even on modules that are off).
    Route::get('/school/modules',      [\App\Http\Controllers\SchoolModuleController::class, 'show']);
    Route::get('/school/access-check', [\App\Http\Controllers\SchoolModuleController::class, 'check']);
    // "Stop impersonating" — uses the school-side Sanctum auth (the
    // impersonation token IS a school-side token) and revokes itself.
    Route::post('/impersonation/exit', [\App\Http\Controllers\Platform\ImpersonationController::class, 'exit']);
    Route::get('/school/bootstrap', [SchoolController::class, 'getBootstrapData']);
    Route::get('/school/configuration', [SchoolController::class, 'getConfiguration']);
    Route::get('/school/config', [SchoolController::class, 'getConfig']);
    Route::get('/school/data', [SchoolController::class, 'getData']);
    Route::get('/school/notifications/counts', [SchoolController::class, 'getNotificationCounts']);
    Route::middleware('subscription.check')->group(function () {
        Route::post('/school/subscription/order', [SubscriptionController::class, 'storeOrder']);
        Route::post('/school/settings', [SchoolController::class, 'updateSettings']);
        Route::get('/school/check-availability', [SchoolController::class, 'checkAvailability']);
        Route::get('/school/settings/email-preview', [SchoolController::class, 'getEmailPreview']);

        // Exam Configuration & Analytics
        Route::get('/school/examination/terms', [ExamConfigurationController::class, 'getTerms']);
        Route::get('/school/examination/types', [ExamConfigurationController::class, 'getTypes']);
        Route::get('/school/examination/grading-scales', [ExamConfigurationController::class, 'getGradingScales']);
        Route::get('/school/examination/structures', [ExamConfigurationController::class, 'getStructures']);
        Route::post('/school/examination/terms', [ExamConfigurationController::class, 'storeTerm']);
        Route::post('/school/examination/types', [ExamConfigurationController::class, 'storeType']);
        Route::post('/school/examination/grading-scales', [ExamConfigurationController::class, 'storeGradingScale']);
        Route::delete('/school/examination/terms/{id}', [ExamConfigurationController::class, 'destroyTerm']);
        Route::delete('/school/examination/types/{id}', [ExamConfigurationController::class, 'destroyType']);
        Route::delete('/school/examination/grading-scales/{id}', [ExamConfigurationController::class, 'destroyGradingScale']);
        Route::post('/school/examination/structures', [ExamConfigurationController::class, 'storeStructure']);
        Route::post('/school/examination/structures/batch', [ExamConfigurationController::class, 'storeStructureBatch']);
        Route::post('/school/examination/structures/clone', [ExamConfigurationController::class, 'cloneStructure']);
        // Two publish-toggle routes that the ExamStructures.tsx UI buttons were
        // already calling — the controller methods existed but were never
        // registered, so every "Publish/Hide" click was 404ing silently.
        Route::patch('/school/examination/structures/batch-publication', [ExamConfigurationController::class, 'batchTogglePublication']);
        Route::patch('/school/examination/structures/{id}/toggle-publication', [ExamConfigurationController::class, 'togglePublication']);

        Route::get('/school/examination/promotion-roster', [AcademicPromotionController::class, 'getPromotionRoster']);
        Route::post('/school/examination/promote', [AcademicPromotionController::class, 'promote']);
        Route::get('/school/examination/analytics/rankings', [\App\Http\Controllers\Admin\ExamAnalyticsController::class, 'getRankings']);
        Route::get('/school/examination/analytics/toppers', [\App\Http\Controllers\Admin\ExamAnalyticsController::class, 'getToppers']);

        // Subjects & Infrastructure
        Route::get('/school/subjects-list', [SchoolController::class, 'getSubjects']);
        Route::post('/school/subjects', [SchoolController::class, 'addSubject']);
        Route::delete('/school/subjects/{id}', [SchoolController::class, 'deleteSubject']);
        Route::get('/school/teachers', [SchoolController::class, 'getTeachers']);
        Route::get('/school/classes', [SchoolController::class, 'getClasses']);
        Route::get('/school/grades', [SchoolController::class, 'getGrades']);
        Route::get('/school/sections', [SchoolController::class, 'getSections']);
        Route::get('/school/classrooms', [SchoolController::class, 'getClassrooms']);
        Route::get('/school/periods', [SchoolController::class, 'getPeriods']);
        Route::get('/school/events', [SchoolController::class, 'getEvents']);

        // Admissions for Admin - status changes & approval
        Route::get('/school/admissions', [AdmissionController::class, 'index']);
        Route::get('/school/admissions/next-number', [AdmissionController::class, 'getNextAdmissionNumber']);
        Route::get('/school/admissions/next-roll', [AdmissionController::class, 'getNextRollNumber']);
        Route::get('/school/classes/{id}/students', [StudentController::class, 'getRoster']);
        Route::post('/school/admissions/resequence-rolls', [AdmissionController::class, 'resequenceRollNumbers']);
        Route::patch('/school/admissions/{id}/status', [AdmissionController::class, 'updateStatus']);
        Route::post('/school/admissions/{id}/approve', [AdmissionController::class, 'approve']);

        // Student Management
        Route::get('/school/students', [StudentController::class, 'index']);
        Route::get('/school/students/{id}', [StudentController::class, 'show']);
        Route::post('/school/students', [StudentController::class, 'store']);
        Route::patch('/school/students/{id}', [StudentController::class, 'update']);
        Route::delete('/school/students/{id}', [StudentController::class, 'destroy']);
        // Dedicated multipart endpoint — separate from update() so a
        // photo change doesn't drag along every validation rule of the
        // edit-profile form. See StudentController::updatePhoto for
        // the auth gates (admin OR class teacher).
        Route::post('/school/students/{id}/photo', [StudentController::class, 'updatePhoto']);

        // Inquiries for Admin - status changes
        Route::get('/school/inquiries', [InquiryController::class, 'index']);
        Route::patch('/school/inquiries/{id}/status', [InquiryController::class, 'updateStatus']);

        // ---- Bulk Student Import (browser-side validate, staged commit) ----
        Route::get('/school/imports/template', [\App\Http\Controllers\StudentImportController::class, 'template']);
        Route::get('/school/imports/existing-master', [\App\Http\Controllers\StudentImportController::class, 'existingMaster']);
        Route::post('/school/imports/materialize-master', [\App\Http\Controllers\StudentImportController::class, 'materializeMaster']);
        Route::post('/school/imports/commit', [\App\Http\Controllers\StudentImportController::class, 'commit']);

        // Session Management
        Route::get('/school/sessions', [SessionController::class, 'index']);
        Route::post('/school/sessions', [SessionController::class, 'store']);
        Route::post('/school/sessions/{id}/activate', [SessionController::class, 'activate']);
        Route::delete('/school/sessions/{id}', [SessionController::class, 'destroy']);
        Route::get('/school/teachers/inactive', [SchoolController::class, 'getInactiveStaff']);
        Route::get('/school/teachers/export', [SchoolController::class, 'exportTeachers']);
        Route::post('/school/teachers', [SchoolController::class, 'addTeacher']);
        Route::delete('/school/teachers/{id}', [SchoolController::class, 'deleteTeacher']);
        Route::post('/school/teachers/{id}/reset-password', [SchoolController::class, 'resetStaffPassword']);
        Route::patch('/school/teachers/{id}/details', [SchoolController::class, 'updateTeacherDetails']);
        // Active class assignments derived from class_subject pivot.
        // Read-only; feeds the "Currently Teaching" subsection on
        // the staff profile that sits beside Specializations.
        Route::get('/school/teachers/{id}/teaching-assignments', [SchoolController::class, 'getTeachingAssignments']);

        Route::post('/school/grades', [SchoolController::class, 'addGrade']);
        Route::delete('/school/grades/{id}', [SchoolController::class, 'deleteGrade']);

        Route::post('/school/sections', [SchoolController::class, 'addSection']);
        Route::delete('/school/sections/{id}', [SchoolController::class, 'deleteSection']);

        Route::post('/school/classes', [SchoolController::class, 'addClass']);
        Route::post('/school/classes/batch-store', [SchoolController::class, 'batchStoreClasses']);
        Route::patch('/school/classes/{id}', [SchoolController::class, 'updateClass']);
        Route::post('/school/classes/{id}/subjects', [SchoolController::class, 'syncSubjects']);
        Route::post('/school/classes/{classId}/subjects/{subjectId}/details', [SchoolController::class, 'updateClassSubjectDetails']);
        Route::post('/school/classes/{classId}/subjects/{subjectId}/upload-note', [SchoolController::class, 'uploadSubjectNoteFile']);
        Route::delete('/school/classes/{id}', [SchoolController::class, 'deleteClass']);

        Route::delete('/school/subjects/{id}', [SchoolController::class, 'deleteSubject']);

        Route::post('/school/classrooms', [SchoolController::class, 'addClassroom']);
        Route::delete('/school/classrooms/{id}', [SchoolController::class, 'deleteClassroom']);

        // Academic Events & Holidays

        Route::get('/school/events', [SchoolController::class, 'getEvents']);
        Route::post('/school/events', [SchoolController::class, 'addEvent']);
        Route::delete('/school/events/{id}', [SchoolController::class, 'deleteEvent']);

        Route::post('/school/periods', [SchoolController::class, 'addPeriod']);
        Route::patch('/school/periods/{id}', [SchoolController::class, 'updatePeriod']);
        Route::delete('/school/periods/{id}', [SchoolController::class, 'deletePeriod']);

        Route::post('/school/role-config', [SchoolController::class, 'updateRoleConfig']);

        Route::post('/school/attendance/mark', [AttendanceController::class, 'mark']);
        Route::post('/school/attendance/regularize', [AttendanceController::class, 'regularize']);

        // Student Attendance (Portal)
        Route::get('/school/student-attendance/classes', [StudentAttendanceController::class, 'getClasses']);
        Route::get('/school/student-attendance/students', [StudentAttendanceController::class, 'getStudentList']);
        Route::get('/school/student-attendance/history', [StudentAttendanceController::class, 'getHistory']);
        Route::get('/school/student-attendance/student-history', [StudentAttendanceController::class, 'getStudentHistory']);
        Route::get('/school/student-attendance/report', [StudentAttendanceController::class, 'getStudentReport']);
        Route::post('/school/student-attendance/submit', [StudentAttendanceController::class, 'submit']);

        Route::post('/timetable', [TimetableController::class, 'addEntry']);
        Route::patch('/timetable/{id}', [TimetableController::class, 'updateEntry']);
        Route::delete('/timetable/{id}', [TimetableController::class, 'deleteEntry']);
        Route::post('/timetable/clone', [TimetableController::class, 'clone']);

        // Substitution Management
        Route::post('/school/substitutions', [SubstitutionController::class, 'store']);
        Route::delete('/school/substitutions/{id}', [SubstitutionController::class, 'destroy']);

        // CMS / Landing Page Management — gated by the
        // `landing_page_widgets` module. When a school's plan or
        // override disables this module, every admin-side request to
        // configure the landing page returns 403 with structured
        // error_code: MODULE_NOT_IN_PLAN. The school admin's SPA
        // catches that and hides the nav item / blocks the route.
        Route::middleware('module:landing_page_widgets')->group(function () {
            Route::get('/school/cms', [LandingPageController::class, 'getCMSData']);

            // Structured website content (Layout 2 multi-page site).
            Route::get('/school/site-content', [\App\Http\Controllers\SiteContentController::class, 'show']);
            Route::put('/school/site-content', [\App\Http\Controllers\SiteContentController::class, 'update']);
            Route::post('/school/site-content/upload', [\App\Http\Controllers\SiteContentController::class, 'upload']);
            Route::get('/school/site-content/staff', [\App\Http\Controllers\SiteContentController::class, 'staff']);
            Route::post('/school/cms/banners', [LandingPageController::class, 'addBanner']);
            Route::delete('/school/cms/banners/{id}', [LandingPageController::class, 'deleteBanner']);

            Route::prefix('school/landing/sections')->group(function () {
                Route::post('/', [LandingPageController::class, 'addSection']);
                Route::put('/{id}', [LandingPageController::class, 'updateSection']);
                Route::post('/reorder', [LandingPageController::class, 'reorderSections']);
                Route::delete('/{id}', [LandingPageController::class, 'deleteSection']);
                Route::post('/{sectionId}/cards', [LandingPageController::class, 'addSectionCard']);
                Route::delete('/{sectionId}/cards/{cardId}', [LandingPageController::class, 'deleteSectionCard']);
            });
        });

        // Email Templates Studio
        Route::get('/school/templates', [EmailTemplateController::class, 'index']);
        Route::patch('/school/templates/{slug}', [EmailTemplateController::class, 'update']);
        // Deleting "resets" the school's customization back to the system default;
        // the system row (school_id=null) is never deleted.
        Route::delete('/school/templates/{slug}', [EmailTemplateController::class, 'destroy']);

        // Timetable Scheduling Data API
        Route::get('/school/timetable-scheduling-data', [TimetableSchedulingController::class, 'getTimetableSchedulingData']);
        Route::post('/school/save-generated-timetable', [TimetableSchedulingController::class, 'saveGeneratedTimetable']);
        Route::post('/school/batch-sync-timetable', [TimetableSchedulingController::class, 'batchSyncTimetable']);
        Route::post('/school/clear-timetable', [TimetableSchedulingController::class, 'clearWeekTimetable']);

        // These GET routes are now also protected by subscription.check
        Route::get('/school/attendance', [AttendanceController::class, 'index']);
        Route::get('/school/attendance/history', [AttendanceController::class, 'history']);
        Route::get('/timetable', [TimetableController::class, 'getEntries']);
        Route::get('/school/substitutions', [SubstitutionController::class, 'index']);
        Route::get('/school/substitutions/conflicts', [SubstitutionController::class, 'fetchConflicts']);

        // Roles & Permissions
        Route::apiResource('/school/roles', RoleController::class)->names([
            'index' => 'school.roles.index',
            'store' => 'school.roles.store',
            'update' => 'school.roles.update',
            'destroy' => 'school.roles.destroy',
        ]);

        // Circulars & Notices
        Route::get('/school/circulars', [CircularController::class, 'index']);
        Route::post('/school/circulars', [CircularController::class, 'store']);
        Route::put('/school/circulars/{id}', [CircularController::class, 'update']);
        Route::delete('/school/circulars/{id}', [CircularController::class, 'destroy']);

        // Fees Management (Admin/Accountant)
        Route::prefix('school/fees')->group(function () {
            Route::get('/transactions', [FeePaymentController::class, 'index']);
            Route::get('/razorpay-transactions', [FeePaymentController::class, 'razorpayIndex']);
            Route::get('/receipts/{feePaymentId}', [FeePaymentController::class, 'receiptShow']);
            Route::post('/payment', [FeePaymentController::class, 'store']);
            Route::apiResource('types', FeeTypeController::class);
            Route::apiResource('assignments', FeeAssignmentController::class);

            // Analytics & Reports
            Route::get('/overview', [FinanceReportController::class, 'getOverview']);
            Route::get('/ledger', [FinanceReportController::class, 'getLedger']);
        });

        // Fines (Admin/Teacher)
        Route::post('/school/fines', [FineController::class, 'store']);

        // Mark Entry (Teacher/Admin)
        Route::prefix('school/marks')->group(function () {
            Route::get('/entry-sheet', [MarkManagementController::class, 'getEntrySheet']);
            Route::post('/submit', [MarkManagementController::class, 'submitMarks']);
            Route::post('/publish', [MarkManagementController::class, 'publishMarks']);
            Route::post('/scholastic', [MarkManagementController::class, 'submitScholastic']);
        });

    });
});

Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()]);
});

//  setup auth for student application routes 
// Logins / OTP verification: slow down password & 6-digit OTP guessing.
// OTP sending: each call sends a real email/SMS.
Route::middleware('throttle:10,1')->post('/students/login', [StudentController::class, 'studentLogin']);
Route::middleware('throttle:5,1')->post('/students/forgot-password', [StudentController::class, 'requestPasswordReset']);
Route::middleware('throttle:10,1')->post('/students/verify-otp', [StudentController::class, 'verifyOtp']);
Route::middleware('throttle:10,1')->post('/students/reset-password', [StudentController::class, 'resetPassword']);

// Parent Application Routes
Route::middleware('throttle:5,1')->post('/parents/send-otp', [ParentAuthController::class, 'sendOtp']);
Route::middleware('throttle:10,1')->post('/parents/verify-otp', [ParentAuthController::class, 'verifyOtp']);
Route::middleware('throttle:10,1')->post('/parents/login-as-student', [ParentAuthController::class, 'loginAsStudent']);
Route::get('/parents/students', [ParentAuthController::class, 'getStudents']);

Route::middleware('auth:sanctum')->group(function () {
    // Student Application Routes (Priority)
    Route::get('/students/profile', [StudentController::class, 'profile']);
    Route::post('/students/logout', [StudentController::class, 'logout']);

    // Academic Data
    Route::get('/students/subjects', [StudentController::class, 'getSubjects']);
    Route::get('/students/timetable', [StudentController::class, 'getTimetable']);
    Route::get('/students/calendar', [StudentController::class, 'getCalendarEvents']);
    Route::get('/students/teachers/{id}', [StudentController::class, 'getTeacherProfile']);
    Route::get('/students/circulars', [CircularController::class, 'studentIndex']);
    Route::get('/students/attendance/report', [StudentAttendanceController::class, 'getPersonalReport']);
    // Lightweight 2-number snapshot driving the dashboard's circular
    // progress rings (Overall Score + Attendance). Replaces hardcoded
    // 85 / 92 literals in the React Native code.
    Route::get('/students/performance', [StudentController::class, 'performance']);
    Route::get('/students/results', [StudentController::class, 'getResults']);
    // Student-facing homework feed — returns BOTH homework (day-wise,
    // text-only) and assignments (due-date, PDF submission). The app
    // splits them into tabs client-side. Each row includes the
    // current student's own submission (if any) for assignments.
    Route::get('/students/homework', [StudentController::class, 'homework']);

    // Student assignment submissions — PDF upload + get my own.
    Route::post('/students/assignments/{id}/submit',     [\App\Http\Controllers\Student\AssignmentSubmissionController::class, 'submit']);
    Route::get ('/students/assignments/{id}/submission', [\App\Http\Controllers\Student\AssignmentSubmissionController::class, 'show']);

    // Notifications & Device Tokens
    Route::post('/students/device-token', [StudentController::class, 'updateDeviceToken']);
    Route::get('/students/notifications/stats', [StudentController::class, 'getNotificationStats']);
    Route::post('/students/notifications/read', [StudentController::class, 'markNotificationsAsRead']);

    // Fees & Online Payments (Student App)
    Route::get('/students/fees/ledger', [StudentController::class, 'getFeeLedger']);
    Route::post('/students/payments/initiate', [OnlinePaymentController::class, 'initiate']);
    Route::post('/students/payments/verify', [OnlinePaymentController::class, 'verify']);

    // Students
    Route::get('/students', [StudentController::class, 'index']);
    Route::get('/students/{id}', [StudentController::class, 'show']);

    // Documents
    Route::get('/student-document-types', [DocumentTypeController::class, 'index']);
    Route::get('/students/fees', [StudentController::class, 'profile']);

    // Teacher Attendance (Self-Attendance)
    Route::get('/teacher/attendance/status', [TeacherAttendanceController::class, 'getStatus']);
    Route::post('/teacher/attendance/check-in', [TeacherAttendanceController::class, 'checkIn']);
    Route::post('/teacher/attendance/check-out', [TeacherAttendanceController::class, 'checkOut']);

    // Teacher Dashboard
    Route::get('/teacher/dashboard', [TeacherDashboardController::class, 'index']);

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/stats', [NotificationController::class, 'getStats']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);

    // Homework — full CRUD for teachers / admins. show / update / destroy
    // were missing before, which meant a teacher could create homework
    // but never edit or delete it from the teacher app.
    Route::get   ('/teacher/homework/options', [TeacherHomeworkController::class, 'options']);
    Route::get   ('/teacher/homework',      [TeacherHomeworkController::class, 'index']);
    Route::post  ('/teacher/homework',      [TeacherHomeworkController::class, 'store']);
    Route::get   ('/teacher/homework/{id}', [TeacherHomeworkController::class, 'show']);
    Route::patch ('/teacher/homework/{id}', [TeacherHomeworkController::class, 'update']);
    Route::delete('/teacher/homework/{id}', [TeacherHomeworkController::class, 'destroy']);
    // Assignment submission management — list every student's
    // submission state for one assignment, then grade individual rows.
    Route::get   ('/teacher/homework/{id}/submissions',  [TeacherHomeworkController::class, 'submissions']);
    Route::patch ('/teacher/submissions/{submissionId}', [TeacherHomeworkController::class, 'gradeSubmission']);
});

Route::post('/webhooks/razorpay', [\App\Http\Controllers\API\WebhookController::class, 'handleRazorpay']);