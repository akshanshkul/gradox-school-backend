<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Services\ModuleAccessService;
use Illuminate\Http\Request;

/**
 * School-facing read endpoints for the module-access system.
 *
 * These do NOT mutate anything — they exist so the school's web /
 * mobile apps can:
 *
 *   - Bootstrap their UI: GET /school/modules returns the enabled
 *     set so the sidebar / tab bar can filter on load. Called once
 *     per session and cached on the client.
 *
 *   - Debug access: GET /school/access-check?module=X returns the
 *     full diagnostic (plan-includes / override-state / reason).
 *     Used by the future "Why can't I see X?" support widget. Also
 *     useful in the saas-admin Modules tab when impersonating.
 *
 * Both endpoints sit inside the auth:sanctum middleware group. No
 * additional gating — knowing your own module set is never gated.
 */
class SchoolModuleController extends Controller
{
    /**
     * Returns the effective module set + plan / override breakdown.
     *
     * Response shape:
     *   {
     *     enabled_modules: ["attendance","fees",...],
     *     plan: { id, name, slug },
     *     overrides: [
     *       { module: "online_classes", state: "enabled",
     *         reason: "Launch trial", expires_at: "2026-09-01T..." }
     *     ],
     *     cache_version: 3
     *   }
     */
    public function show(Request $request)
    {
        $school = $request->user()->school;
        if (!$school) {
            return response()->json(['success' => false, 'message' => 'No school context.'], 404);
        }

        $codes = ModuleAccessService::for($school)->all();

        // Per-module rich info so the SPA can render proper labels
        // and icons in the sidebar without an extra round-trip.
        $modules = Module::query()
            ->whereIn('code', $codes)
            ->orderBy('sort_order')
            ->get(['code', 'name', 'category', 'icon', 'surfaces'])
            ->map(fn($m) => [
                'code'     => $m->code,
                'name'     => $m->name,
                'category' => $m->category,
                'icon'     => $m->icon,
                'surfaces' => $m->surfaces,
            ]);

        $overrides = $school->moduleOverrides()
            ->with('module:id,code,name')
            ->get()
            ->filter(fn($o) => $o->isActive())
            ->map(fn($o) => [
                'module'     => $o->module?->code,
                'state'      => $o->state,
                'reason'     => $o->reason,
                'expires_at' => optional($o->expires_at)->toIso8601String(),
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data'    => [
                'enabled_modules' => $codes,
                'modules'         => $modules,
                'plan'            => $school->plan ? [
                    'id'   => $school->plan->id,
                    'name' => $school->plan->name,
                    'slug' => $school->plan->slug,
                ] : null,
                'overrides'       => $overrides,
                'cache_version'   => (int) $school->module_cache_version,
            ],
        ]);
    }

    /**
     * Diagnose a single module's access state. Used by support and
     * the "Why can't I see X?" widget on the school admin dashboard.
     *
     *   GET /school/access-check?module=attendance
     */
    public function check(Request $request)
    {
        $request->validate(['module' => 'required|string|max:64']);

        $school = $request->user()->school;
        if (!$school) {
            return response()->json(['success' => false, 'message' => 'No school context.'], 404);
        }

        $diag = ModuleAccessService::diagnose($school, $request->module);
        return response()->json([
            'success' => true,
            'data'    => $diag,
        ]);
    }
}
