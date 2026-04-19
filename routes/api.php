<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
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
use App\Http\Controllers\API\OnlinePaymentController;
use App\Http\Controllers\Teacher\FineController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Public Landing Pages & Inquiries
Route::get('/school/public', [SchoolController::class, 'getPublicSchoolInfo']);
Route::get('/schools/search', [SchoolController::class, 'searchSchools']);
Route::get('/school/check-slug-availability', [SchoolController::class, 'checkPublicSlugAvailability']);
Route::get('/school/public/cms', [LandingPageController::class, 'getCMSData']);

Route::post('/inquiries', [InquiryController::class, 'store']);
Route::post('/admissions', [AdmissionController::class, 'store']);


// teacher and admin
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', function (Request $request) {
        return $request->user()->load([
            'school' => function ($q) {
                $q->select('id', 'name', 'slug', 'logo_path', 'current_session', 'plan_name', 'subscription_status');
            },
            'role_relation',
            'managedClasses'
        ]);
    });
    Route::get('/school/config', [SchoolController::class, 'getConfig']);
    Route::get('/school/data', [SchoolController::class, 'getData']);
    Route::get('/school/notifications/counts', [SchoolController::class, 'getNotificationCounts']);
    Route::middleware('subscription.check')->group(function () {
        Route::post('/school/subscription/order', [SubscriptionController::class, 'storeOrder']);
        Route::post('/school/settings', [SchoolController::class, 'updateSettings']);
        Route::get('/school/check-availability', [SchoolController::class, 'checkAvailability']);
        Route::get('/school/settings/email-preview', [SchoolController::class, 'getEmailPreview']);

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

        // Inquiries for Admin - status changes
        Route::get('/school/inquiries', [InquiryController::class, 'index']);
        Route::patch('/school/inquiries/{id}/status', [InquiryController::class, 'updateStatus']);

        // Resource Routes - School Setup
        Route::get('/school/teachers', [SchoolController::class, 'getTeachers']);
        Route::get('/school/classes', [SchoolController::class, 'getClasses']);
        
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

        Route::post('/school/grades', [SchoolController::class, 'addGrade']);
        Route::delete('/school/grades/{id}', [SchoolController::class, 'deleteGrade']);

        Route::post('/school/sections', [SchoolController::class, 'addSection']);
        Route::delete('/school/sections/{id}', [SchoolController::class, 'deleteSection']);

        Route::post('/school/classes', [SchoolController::class, 'addClass']);
        Route::post('/school/classes/batch-store', [SchoolController::class, 'batchStoreClasses']);
        Route::patch('/school/classes/{id}', [SchoolController::class, 'updateClass']);
        Route::post('/school/classes/{id}/subjects', [SchoolController::class, 'syncSubjects']);
        Route::delete('/school/classes/{id}', [SchoolController::class, 'deleteClass']);

        Route::post('/school/subjects', [SchoolController::class, 'addSubject']);
        Route::delete('/school/subjects/{id}', [SchoolController::class, 'deleteSubject']);

        Route::post('/school/classrooms', [SchoolController::class, 'addClassroom']);
        Route::delete('/school/classrooms/{id}', [SchoolController::class, 'deleteClassroom']);

        // Academic Events & Holidays

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

        // CMS / Landing Page Management
        Route::get('/school/cms', [LandingPageController::class, 'getCMSData']);
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

        // Email Templates Studio
        Route::get('/school/templates', [EmailTemplateController::class, 'index']);
        Route::patch('/school/templates/{slug}', [EmailTemplateController::class, 'update']);

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
            Route::post('/payment', [FeePaymentController::class, 'store']);
            Route::apiResource('types', FeeTypeController::class);
            Route::apiResource('assignments', FeeAssignmentController::class);
            
            // Analytics & Reports
            Route::get('/overview', [FinanceReportController::class, 'getOverview']);
            Route::get('/ledger', [FinanceReportController::class, 'getLedger']);
        });

        // Fines (Admin/Teacher)
        Route::post('/school/fines', [FineController::class, 'store']);
    });
});

Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()]);
});

//  setup auth for student application routes 
Route::post('/students/login', [StudentController::class, 'studentLogin']);
Route::post('/students/forgot-password', [StudentController::class, 'requestPasswordReset']);
Route::post('/students/verify-otp', [StudentController::class, 'verifyOtp']);
Route::post('/students/reset-password', [StudentController::class, 'resetPassword']);

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
});

Route::post('/webhooks/razorpay', [\App\Http\Controllers\API\WebhookController::class, 'handleRazorpay']);