<?php

namespace App\Http\Middleware;

use App\Models\Module;
use App\Services\ModuleAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-route module-availability gate.
 *
 * Usage:
 *   Route::middleware('module:attendance')->group(function () {
 *       Route::get('/attendance/...', ...);
 *   });
 *
 * Behavior:
 *   - If no authenticated user / no school context → passes through.
 *     This is intentional: public endpoints (landing page fetch,
 *     password-reset request) live OUTSIDE the auth group anyway and
 *     wouldn't carry this middleware. If a developer accidentally
 *     puts module:X on a pre-auth route the right outcome is "let it
 *     through" — we don't want auth probing to leak module state.
 *
 *   - If the module is `is_core` → always passes. Belt-and-braces:
 *     even if a developer marks a core route with module:X by
 *     mistake, the core flag wins. Routes tagged `@core` (no
 *     middleware) and routes tagged `module:core_thing` both work.
 *
 *   - During an impersonation session by a platform-admin, the gate
 *     is bypassed and a synthetic 'allowed' audit row is written. The
 *     impersonator can debug a school's restricted feature without
 *     having to first un-restrict it.
 *
 *   - On denial, returns a structured 403 with `error_code:
 *     MODULE_NOT_IN_PLAN`. Mobile apps that understand the code
 *     render a friendly upgrade prompt; older apps fall back to the
 *     human-readable `message`.
 */
class EnsureModuleAvailable
{
    public function handle(Request $request, Closure $next, string $moduleCode): Response
    {
        $user   = $request->user();
        $school = $user?->school;

        // No user, no school — pass through. See class docblock.
        if (!$school) {
            return $next($request);
        }

        $module = Module::where('code', $moduleCode)->first();

        // Core modules are never gated. If the code is missing from
        // the catalog entirely we fail OPEN — losing access to a
        // miscoded route on production is worse than letting it
        // through. The CI artisan command catches missing codes.
        if (!$module || $module->is_core) {
            return $next($request);
        }

        // Impersonation bypass — but log it so the audit trail still
        // captures who touched what under impersonation.
        if (method_exists($user, 'isImpersonating') && $user->isImpersonating()) {
            ModuleAccessService::logDenied(
                $school, $module, $user->id, $request->path(), 'impersonation_bypass'
            );
            return $next($request);
        }

        if (ModuleAccessService::schoolHas($school, $moduleCode)) {
            return $next($request);
        }

        // Denied. Determine reason for the audit log and the
        // structured response.
        $diag = ModuleAccessService::diagnose($school, $moduleCode);
        ModuleAccessService::logDenied(
            $school, $module, $user->id, $request->path(),
            $diag['reason'] ?? 'not_in_plan'
        );

        return response()->json([
            'success'      => false,
            'error_code'   => 'MODULE_NOT_IN_PLAN',
            'module'       => $moduleCode,
            'module_name'  => $module->name,
            'school_id'    => $school->id,
            'message'      => $diag['message'] ?? "The {$module->name} module is not included in your plan.",
        ], 403);
    }
}
