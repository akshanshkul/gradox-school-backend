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

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Public Landing Pages & Inquiries
Route::get('/school/public', [SchoolController::class, 'getPublicSchoolInfo']);
Route::get('/school/public/cms', [LandingPageController::class, 'getCMSData']); // New open route but could filter by schoolId param later... wait, getPublicSchoolInfo should return banners and sections too. I'll modify the public method instead of adding a new one.

Route::post('/inquiries', [InquiryController::class, 'store']);
Route::post('/admissions', [AdmissionController::class, 'store']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', function (Request $request) {
        return $request->user()->load('school', 'role_relation', 'managedClasses');
    });
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
        // Students
        Route::get('/students', [StudentController::class, 'index']);
        Route::get('/students/{id}', [StudentController::class, 'show']);
        
        // Documents
        Route::get('/student-document-types', [DocumentTypeController::class, 'index']);
        Route::post('/school/events', [SchoolController::class, 'addEvent']);
        Route::delete('/school/events/{id}', [SchoolController::class, 'deleteEvent']);

        Route::post('/school/periods', [SchoolController::class, 'addPeriod']);
        Route::patch('/school/periods/{id}', [SchoolController::class, 'updatePeriod']);
        Route::delete('/school/periods/{id}', [SchoolController::class, 'deletePeriod']);

        Route::post('/school/role-config', [SchoolController::class, 'updateRoleConfig']);

        Route::post('/school/attendance/mark', [AttendanceController::class, 'mark']);
        Route::post('/school/attendance/regularize', [AttendanceController::class, 'regularize']);

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
    });

});
Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()]);
});
