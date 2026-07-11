<?php

namespace App\Services;

use App\Models\Module;
use App\Models\ModuleAccessLog;
use App\Models\School;
use App\Models\SchoolModuleOverride;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for "what modules can this school / user
 * touch right now?". Every other piece of code — middleware, app
 * filters, sidebars, schedulers — defers to the methods on this class.
 *
 * Why a service (not a model method): the resolved set depends on
 * THREE moving inputs (plan, overrides, expiry-clock) and we want the
 * resolution logic in one testable place. Models stay thin.
 *
 * The hot path is `schoolHas()`. It reads the per-school cached
 * bitmap column — a single string read, decoded once per request via
 * School::hasModule(). The bitmap is rebuilt only on plan or override
 * change, so the read-side cost is roughly zero.
 *
 * The slow path is `recompute()`. Runs inside the transaction that
 * changed inputs. Uses bulk queries; touches at most O(modules-in-
 * catalog) rows.
 */
class ModuleAccessService
{
    /**
     * Resolve and return the set of enabled module codes for a school.
     * Reads from the cached bitmap if present; computes + caches if
     * the bitmap is empty (first-call seeding for legacy schools that
     * existed before Phase A migration ran).
     */
    public static function for(School $school): Collection
    {
        if ($school->module_cache_bitmap === null || $school->module_cache_bitmap === '') {
            self::recompute($school);
            $school->refresh();
        }
        $bitmap = (string) ($school->module_cache_bitmap ?? '');
        return collect($bitmap === '' ? [] : explode(' ', $bitmap));
    }

    /**
     * O(1) check used by the request-time hot path
     * (EnsureModuleAvailable middleware). Delegates to the model so
     * the per-request decoded-set cache stays warm.
     */
    public static function schoolHas(School $school, string $code): bool
    {
        if ($school->module_cache_bitmap === null) {
            // Lazy first-time seed for a school that pre-dates the
            // migration. After this call the bitmap is persisted.
            self::recompute($school);
            $school->refresh();
            $school->refreshModuleCache();
        }
        return $school->hasModule($code);
    }

    /**
     * Compose with existing RBAC: the user must (a) belong to a
     * school that has the module enabled AND (b) have role/permission
     * to perform the requested action.
     *
     * The $action defaults to 'read'. Pass 'update' / 'create' / etc.
     * if you want to check write-level rights too. RBAC checks reuse
     * the existing User::hasPermission() helper — no new auth code.
     */
    public static function userCanAccess(User $user, string $moduleCode, string $action = 'read'): bool
    {
        $school = $user->school;
        if (!$school) return false;

        if (!self::schoolHas($school, $moduleCode)) return false;

        // Re-use existing RBAC. The user model's hasPermission() returns
        // true for admins or for explicit grants. Module codes map 1:1
        // onto the existing permission "resource" keys where they
        // already exist (academic, students, fees, ...); for modules
        // without a pre-existing resource key, the check falls through
        // to "admin or class-teacher" which is the right default for
        // platform-level features.
        if (method_exists($user, 'hasPermission')) {
            return (bool) $user->hasPermission($moduleCode, $action);
        }
        return true;
    }

    /**
     * Recompute and persist a school's module bitmap. Called from:
     *   - SchoolModuleOverride save/delete hooks
     *   - school plan_id change
     *   - plan_module catalog changes (cascades over all schools on
     *     the affected plan)
     *   - lazy first-call seeding for legacy rows
     *
     * The recompute is idempotent: running it twice yields the same
     * bitmap and same version increment.
     */
    public static function recompute(School $school): void
    {
        $codes = self::resolveCodes($school);
        sort($codes); // deterministic order makes the bitmap string
                      // comparable across rebuilds (helps with tests
                      // and diff debugging).

        DB::table('schools')
            ->where('id', $school->id)
            ->update([
                'module_cache_bitmap'  => implode(' ', $codes),
                'module_cache_version' => DB::raw('module_cache_version + 1'),
                'updated_at'           => now(),
            ]);

        // Reset per-request cache on the in-memory model instance so
        // subsequent calls in the same request see the new bitmap.
        $school->module_cache_bitmap = implode(' ', $codes);
        $school->refreshModuleCache();
    }

    /**
     * Bulk recompute for all schools on a given plan. Called when
     * plan_modules is mutated for that plan. One query per school is
     * unavoidable since each school's override set may differ; we
     * batch the school list and let each row recompute.
     */
    public static function recomputeForPlan(int $planId): void
    {
        School::where('plan_id', $planId)->each(function (School $s) {
            self::recompute($s);
        });
    }

    /**
     * The core resolver. Computes the SET of module codes this school
     * is currently entitled to:
     *
     *   1. Start from plan's included modules (plan_modules where
     *      is_included = true).
     *   2. Add modules from any active 'enabled' override.
     *   3. Remove modules from any active 'disabled' override.
     *   4. Always include is_core modules — these can never be
     *      disabled regardless of plan or override state.
     *
     * "Active override" = state set AND (expires_at is NULL OR > now()).
     * Expired overrides are ignored here but cleaned up by the
     * modules:expire-overrides scheduled command — until that command
     * runs, ignoring them gives the correct behaviour anyway.
     */
    private static function resolveCodes(School $school): array
    {
        // --- 1. Core modules (always on) ---
        $coreCodes = Module::query()
            ->where('is_core', true)
            ->pluck('code')
            ->all();

        // --- 2. Plan-included modules ---
        $planCodes = [];
        if ($school->plan_id) {
            $planCodes = DB::table('plan_modules')
                ->join('modules', 'modules.id', '=', 'plan_modules.module_id')
                ->where('plan_modules.plan_id', $school->plan_id)
                ->where('plan_modules.is_included', true)
                ->whereNull('modules.deleted_at')
                ->pluck('modules.code')
                ->all();
        }

        // --- 3. Overrides ---
        $overrideRows = DB::table('school_module_overrides')
            ->join('modules', 'modules.id', '=', 'school_module_overrides.module_id')
            ->where('school_module_overrides.school_id', $school->id)
            ->where(function ($q) {
                $q->whereNull('school_module_overrides.expires_at')
                  ->orWhere('school_module_overrides.expires_at', '>', now());
            })
            ->whereNull('modules.deleted_at')
            ->select('modules.code', 'school_module_overrides.state')
            ->get();

        $enabledByOverride  = [];
        $disabledByOverride = [];
        foreach ($overrideRows as $row) {
            if ($row->state === 'enabled')  $enabledByOverride[]  = $row->code;
            if ($row->state === 'disabled') $disabledByOverride[] = $row->code;
        }

        // Compose: (core ∪ plan ∪ enabled-overrides) − disabled-overrides.
        // Core wins over disabled overrides — that's why core is added
        // back AFTER the diff (and is_core enforced again by middleware).
        $set = array_unique(array_merge($planCodes, $enabledByOverride));
        $set = array_diff($set, $disabledByOverride);
        $set = array_unique(array_merge($set, $coreCodes));
        return array_values($set);
    }

    /**
     * Diagnostic helper for support tickets and the SaaS-admin
     * "Why can't I see X" UI. Returns a structured explanation of
     * the decision for a single (school, module) pair.
     */
    public static function diagnose(School $school, string $moduleCode): array
    {
        $module = Module::where('code', $moduleCode)->first();
        if (!$module) {
            return [
                'allowed' => false,
                'reason'  => 'unknown_module',
                'message' => "No module with code '{$moduleCode}' exists in the catalog.",
            ];
        }
        if ($module->is_core) {
            return [
                'allowed' => true,
                'reason'  => 'core',
                'message' => 'This is a core module — always available.',
            ];
        }

        $inPlan = $school->plan_id ? DB::table('plan_modules')
            ->where('plan_id', $school->plan_id)
            ->where('module_id', $module->id)
            ->where('is_included', true)
            ->exists() : false;

        $override = SchoolModuleOverride::where('school_id', $school->id)
            ->where('module_id', $module->id)
            ->first();
        $overrideActive = $override && $override->isActive();

        $allowed = self::schoolHas($school, $moduleCode);
        $reason  = $allowed
            ? ($overrideActive && $override->state === 'enabled' ? 'override_enabled'
                : ($inPlan ? 'in_plan' : 'core'))
            : ($overrideActive && $override->state === 'disabled' ? 'overridden_disabled'
                : 'not_in_plan');

        return [
            'allowed'        => $allowed,
            'reason'         => $reason,
            'plan_includes'  => $inPlan,
            'override_state' => $overrideActive ? $override->state : null,
            'override_reason' => $overrideActive ? $override->reason : null,
            'expires_at'     => $overrideActive ? optional($override->expires_at)->toIso8601String() : null,
            'message'        => self::messageFor($reason, $module->name),
        ];
    }

    private static function messageFor(string $reason, string $moduleName): string
    {
        return match ($reason) {
            'in_plan'              => "{$moduleName} is included in this school's current plan.",
            'core'                 => "{$moduleName} is a core platform module — always available.",
            'override_enabled'     => "{$moduleName} is enabled for this school by a SaaS-admin override.",
            'overridden_disabled'  => "{$moduleName} has been temporarily disabled for this school.",
            'not_in_plan'          => "{$moduleName} is not included in this school's current plan.",
            'unknown_module'       => "Unknown module code.",
            default                => "{$moduleName} access could not be determined.",
        };
    }

    /**
     * Log a denial event. Called by EnsureModuleAvailable middleware
     * when a request fails the gate. We intentionally do NOT log
     * allowed events — they happen on every request and the volume
     * would balloon the table without adding signal.
     *
     * Failures here are swallowed: an audit-log write failing must
     * never break user requests.
     */
    public static function logDenied(
        School $school,
        ?Module $module,
        ?int $userId,
        string $route,
        string $deniedReason
    ): void {
        try {
            ModuleAccessLog::create([
                'school_id'     => $school->id,
                'module_id'     => $module?->id,
                'user_id'       => $userId,
                'route'         => substr($route, 0, 255),
                'outcome'       => 'denied',
                'denied_reason' => $deniedReason,
                'created_at'    => now(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('module_access_log write failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Daily cron entry point — process any overrides whose
     * expires_at has passed. Removes them, recomputes the affected
     * school's bitmap, and returns a count for the command output.
     */
    public static function processExpiredOverrides(): int
    {
        $expired = SchoolModuleOverride::whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        $count = 0;
        foreach ($expired as $override) {
            $school = $override->school;
            $override->delete(); // boot hook recomputes school bitmap
            // The recompute() inside the model hook handles the bitmap
            // refresh — we just need to count.
            $count++;
        }
        return $count;
    }
}
