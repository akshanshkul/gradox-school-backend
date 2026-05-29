<?php

use App\Http\Controllers\Platform\AnalyticsController;
use App\Http\Controllers\Platform\AuditLogController;
use App\Http\Controllers\Platform\AuthController;
use App\Http\Controllers\Platform\BroadcastController;
use App\Http\Controllers\Platform\ImpersonationController;
use App\Http\Controllers\Platform\PlanController;
use App\Http\Controllers\Platform\ProfileController;
use App\Http\Controllers\Platform\ReminderController;
use App\Http\Controllers\Platform\ReportsController;
use App\Http\Controllers\Platform\SchoolController;
use App\Http\Controllers\Platform\SubscriptionController;
use App\Http\Controllers\Platform\SystemController;
use App\Http\Controllers\Platform\TeamController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform Admin Routes
|--------------------------------------------------------------------------
|
| Mounted at /api/platform/* — completely isolated from the school-facing
| API. Uses the dedicated `platform_admin` Sanctum guard.
|
*/

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'service' => 'platform-admin-api',
    'time' => now()->toIso8601String(),
]));

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:platform_admin')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // My profile
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::post('/profile/password', [ProfileController::class, 'changePassword']);

    // Platform admin team (owner-only writes)
    Route::get('/team', [TeamController::class, 'index']);
    Route::post('/team', [TeamController::class, 'store']);
    Route::put('/team/{id}', [TeamController::class, 'update']);
    Route::delete('/team/{id}', [TeamController::class, 'destroy']);

    // Schools (tenants)
    Route::get('/schools', [SchoolController::class, 'index']);
    Route::post('/schools', [SchoolController::class, 'store']);
    Route::get('/schools/{id}', [SchoolController::class, 'show']);
    Route::put('/schools/{id}', [SchoolController::class, 'update']);
    Route::post('/schools/{id}/suspend', [SchoolController::class, 'suspend']);
    Route::post('/schools/{id}/activate', [SchoolController::class, 'activate']);

    // Subscriptions / plan management for a given school
    Route::post('/schools/{id}/subscription/assign-plan', [SubscriptionController::class, 'assignPlan']);
    Route::post('/schools/{id}/subscription/extend', [SubscriptionController::class, 'extend']);
    Route::get('/schools/{id}/subscription/payments', [SubscriptionController::class, 'payments']);

    // Impersonation — drop into a school as its administrator
    Route::post('/schools/{id}/impersonate', [ImpersonationController::class, 'enter']);

    // Plan-expiry reminders — email + in-app notification to school admins
    Route::get('/schools/{id}/reminder/preview', [ReminderController::class, 'preview']);
    Route::get('/schools/{id}/reminder/history', [ReminderController::class, 'history']);
    Route::post('/schools/{id}/reminder/send', [ReminderController::class, 'send']);

    // Plans catalog
    Route::get('/plans', [PlanController::class, 'index']);
    Route::post('/plans', [PlanController::class, 'store']);
    Route::get('/plans/{id}', [PlanController::class, 'show']);
    Route::put('/plans/{id}', [PlanController::class, 'update']);
    Route::delete('/plans/{id}', [PlanController::class, 'destroy']);

    // Analytics
    Route::get('/analytics/overview', [AnalyticsController::class, 'overview']);
    Route::get('/analytics/growth', [AnalyticsController::class, 'growth']);
    Route::get('/analytics/expiring-soon', [AnalyticsController::class, 'expiringSoon']);

    // Broadcasts to schools
    Route::get('/broadcasts', [BroadcastController::class, 'index']);
    Route::post('/broadcasts', [BroadcastController::class, 'send']);

    // Revenue reports
    Route::get('/reports/revenue', [ReportsController::class, 'revenue']);
    Route::get('/reports/revenue.csv', [ReportsController::class, 'revenueCsv']);

    // System health
    Route::get('/system/health', [SystemController::class, 'health']);

    // Audit log
    Route::get('/audit-logs', [AuditLogController::class, 'index']);
});
