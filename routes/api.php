<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SchoolController;
use App\Http\Controllers\TimetableController;
use App\Http\Controllers\SubstitutionController;
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Public Landing Pages & Inquiries
Route::get('/school/public', [SchoolController::class, 'getPublicSchoolInfo']);
Route::get('/school/public/cms', [\App\Http\Controllers\LandingPageController::class, 'getCMSData']); // New open route but could filter by schoolId param later... wait, getPublicSchoolInfo should return banners and sections too. I'll modify the public method instead of adding a new one.

Route::post('/inquiries', [\App\Http\Controllers\InquiryController::class, 'store']);
Route::post('/admissions', [\App\Http\Controllers\AdmissionController::class, 'store']);

Route::middleware('auth:sanctum')->group(function () {
Route::post('/logout', [AuthController::class, 'logout']);
Route::get('/user', function (Request $request) {
return $request->user()->load('school');
});
Route::get('/school/data', [SchoolController::class, 'getData']);
Route::get('/school/notifications/counts', [SchoolController::class, 'getNotificationCounts']);
    Route::middleware('subscription.check')->group(function () {
        Route::post('/school/subscription/order', [App\Http\Controllers\SubscriptionController::class, 'storeOrder']);
        Route::post('/school/settings', [SchoolController::class, 'updateSettings']);
        Route::get('/school/settings/email-preview', [SchoolController::class, 'getEmailPreview']);

        // Admissions for Admin - status changes
        Route::patch('/school/admissions/{id}/status', [\App\Http\Controllers\AdmissionController::class, 'updateStatus']);
        
        // Inquiries for Admin - status changes
        Route::patch('/school/inquiries/{id}/status', [\App\Http\Controllers\InquiryController::class, 'updateStatus']);

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

        Route::post('/school/attendance/mark', [App\Http\Controllers\AttendanceController::class, 'mark']);
        Route::post('/school/attendance/regularize', [App\Http\Controllers\AttendanceController::class, 'regularize']);

        Route::post('/timetable', [TimetableController::class, 'addEntry']);
        Route::patch('/timetable/{id}', [TimetableController::class, 'updateEntry']);
        Route::delete('/timetable/{id}', [TimetableController::class, 'deleteEntry']);
        Route::post('/timetable/clone', [TimetableController::class, 'clone']);

        // Substitution Management
        Route::post('/school/substitutions', [SubstitutionController::class, 'store']);
        Route::delete('/school/substitutions/{id}', [SubstitutionController::class, 'destroy']);

        // CMS / Landing Page Management
        Route::get('/school/cms', [\App\Http\Controllers\LandingPageController::class, 'getCMSData']);
        Route::post('/school/cms/banners', [\App\Http\Controllers\LandingPageController::class, 'addBanner']);
        Route::delete('/school/cms/banners/{id}', [\App\Http\Controllers\LandingPageController::class, 'deleteBanner']);
        Route::post('/school/cms/sections', [\App\Http\Controllers\LandingPageController::class, 'addSection']);
        // corrected the delete route to match frontend
        Route::delete('/school/cms/sections/{id}', [\App\Http\Controllers\LandingPageController::class, 'deleteSection']);
        Route::post('/school/cms/sections/{sectionId}/cards', [\App\Http\Controllers\LandingPageController::class, 'addSectionCard']);
        Route::delete('/school/cms/sections/{sectionId}/cards/{cardId}', [\App\Http\Controllers\LandingPageController::class, 'deleteSectionCard']);

        // Email Templates Studio
        Route::get('/school/templates', [\App\Http\Controllers\EmailTemplateController::class, 'index']);
        Route::patch('/school/templates/{slug}', [\App\Http\Controllers\EmailTemplateController::class, 'update']);
        
        // Timetable Scheduling Data API
        Route::get('/school/timetable-scheduling-data', [\App\Http\Controllers\TimetableSchedulingController::class, 'getTimetableSchedulingData']);
    });

    Route::get('/school/admissions', [\App\Http\Controllers\AdmissionController::class, 'index']);
    Route::get('/school/inquiries', [\App\Http\Controllers\InquiryController::class, 'index']);
    Route::get('/school/attendance', [App\Http\Controllers\AttendanceController::class, 'index']);
    Route::get('/school/attendance/history', [App\Http\Controllers\AttendanceController::class, 'history']);
    Route::get('/timetable', [TimetableController::class, 'getEntries']);
    Route::get('/school/substitutions', [SubstitutionController::class, 'index']);
    Route::get('/school/substitutions/conflicts', [SubstitutionController::class, 'fetchConflicts']);
});
Route::get('/health', function () {
return response()->json(['status' => 'ok', 'timestamp' => now()]);
});
