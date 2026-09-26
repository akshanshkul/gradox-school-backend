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

// Login is throttled to 5 attempts per minute per IP. Laravel's `throttle`
// limiter falls back to IP keying when no auth user is present, which is
// what we want before login. A focused per-email RateLimiter would be
// tighter — see RouteServiceProvider::configureRateLimiting if you want to
// add one — but 5/min/IP already kills any practical password-spray.
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/login/send-otp', [AuthController::class, 'sendOtp']);
    Route::post('/login/verify-otp', [AuthController::class, 'verifyOtp']);
});

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
    // Trial extension (separate from /extend which moves subscription_expires_at)
    Route::post('/schools/{id}/subscription/extend-trial', [SubscriptionController::class, 'extendTrial']);
    // Per-school override on plan's student cap. Set to 0 to remove the override.
    Route::post('/schools/{id}/student-limit-override', [SubscriptionController::class, 'setStudentLimitOverride']);
    // Plan + usage snapshot — same shape as school-side /school/usage, used
    // by the platform admin's SchoolDetail page so we don't redo the math.
    Route::get('/schools/{id}/usage', [SchoolController::class, 'usage']);

    // Impersonation — drop into a school as its administrator
    //   1. POST /schools/{id}/impersonate              → sends OTP email
    //   2. POST /impersonation/confirm-otp             → returns handoff_code
    //   3. (optional) POST /impersonation/resend-otp   → fresh code, same request_id
    //
    // The school-side `/api/impersonation/exchange` + `/api/impersonation/exit`
    // endpoints (declared in api.php) close the loop on the school frontend.
    Route::post('/schools/{id}/impersonate', [ImpersonationController::class, 'enter']);
    Route::post('/impersonation/confirm-otp', [ImpersonationController::class, 'confirmOtp']);
    Route::post('/impersonation/resend-otp', [ImpersonationController::class, 'resendOtp']);

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
    // Send rate-limited to 5 broadcasts per hour per admin — broadcasts go
    // to every school at once, so accidental rapid-fire double-clicks would
    // multiply email/push outbound traffic. The dedicated middleware below
    // also enforces an idempotency key on top of the throttle.
    Route::middleware('throttle:5,60')->post('/broadcasts', [BroadcastController::class, 'send']);

    // Revenue reports
    Route::get('/reports/revenue', [ReportsController::class, 'revenue']);
    Route::get('/reports/revenue.csv', [ReportsController::class, 'revenueCsv']);

    // System health
    Route::get('/system/health', [SystemController::class, 'health']);

    // Mail health diagnostics — surfaces SMTP / API config, lets ops
    // probe the connection, send a test email, and see recent failures.
    // Useful when schools complain "OTP didn't arrive" — first
    // checkpoint is "is the mail service up at all?"
    Route::get   ('/mail/config',   [\App\Http\Controllers\Platform\MailDiagnosticController::class, 'config']);
    Route::get   ('/mail/probe',    [\App\Http\Controllers\Platform\MailDiagnosticController::class, 'probe']);
    Route::post  ('/mail/test',     [\App\Http\Controllers\Platform\MailDiagnosticController::class, 'test']);
    Route::get   ('/mail/failures', [\App\Http\Controllers\Platform\MailDiagnosticController::class, 'failures']);
    Route::delete('/mail/failures', [\App\Http\Controllers\Platform\MailDiagnosticController::class, 'clearFailures']);

    // Audit log
    Route::get('/audit-logs', [AuditLogController::class, 'index']);

    // Module Access Control — catalog, plan composition, per-school
    // overrides, and access logs. All mutations write to the platform
    // audit log; all deletes are guarded by usage checks.
    Route::get   ('/modules',                                  [\App\Http\Controllers\Platform\ModuleController::class, 'index']);
    Route::post  ('/modules',                                  [\App\Http\Controllers\Platform\ModuleController::class, 'store']);
    Route::patch ('/modules/{id}',                             [\App\Http\Controllers\Platform\ModuleController::class, 'update']);
    Route::delete('/modules/{id}',                             [\App\Http\Controllers\Platform\ModuleController::class, 'destroy']);

    Route::get   ('/plans/{plan}/modules',                     [\App\Http\Controllers\Platform\ModuleController::class, 'planModules']);
    Route::put   ('/plans/{plan}/modules',                     [\App\Http\Controllers\Platform\ModuleController::class, 'updatePlanModules']);
    Route::get   ('/plans/{plan}/modules/impact',              [\App\Http\Controllers\Platform\ModuleController::class, 'planImpact']);

    Route::get   ('/schools/{school}/modules',                 [\App\Http\Controllers\Platform\ModuleController::class, 'schoolModules']);
    Route::post  ('/schools/{school}/modules/override',        [\App\Http\Controllers\Platform\ModuleController::class, 'setOverride']);
    Route::delete('/schools/{school}/modules/override/{moduleId}', [\App\Http\Controllers\Platform\ModuleController::class, 'clearOverride']);

    Route::get   ('/module-access-logs',                       [\App\Http\Controllers\Platform\ModuleController::class, 'accessLogs']);
});
