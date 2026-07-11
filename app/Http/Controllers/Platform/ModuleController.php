<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Models\Plan;
use App\Models\School;
use App\Models\SchoolModuleOverride;
use App\Services\ModuleAccessService;
use App\Services\PlatformAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SaaS-admin endpoints for managing the module catalog, plan
 * composition, and per-school overrides.
 *
 *   --- Catalog ---
 *   GET    /platform/modules                 list catalog
 *   POST   /platform/modules                 create module
 *   PATCH  /platform/modules/{id}            update module
 *   DELETE /platform/modules/{id}            soft-delete (refuses if in use)
 *
 *   --- Plan composition ---
 *   GET    /platform/plans/{plan}/modules         current included set
 *   PUT    /platform/plans/{plan}/modules         atomic replace
 *   GET    /platform/plans/{plan}/modules/impact  who's affected if we change?
 *
 *   --- Per-school overrides ---
 *   GET    /platform/schools/{school}/modules               full module view
 *   POST   /platform/schools/{school}/modules/override      grant or disable
 *   DELETE /platform/schools/{school}/modules/override/{m}  clear override
 *
 *   --- Access logs ---
 *   GET    /platform/module-access-logs            recent denied events
 *
 * All routes are mounted inside the existing platform-admin auth
 * middleware. Every mutation writes a row to the platform audit log.
 */
class ModuleController extends Controller
{
    public function __construct(private PlatformAuditService $audit) {}

    // -----------------------------------------------------------------
    //  CATALOG
    // -----------------------------------------------------------------

    public function index(Request $request)
    {
        $modules = Module::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        // Augment with usage counts so the UI can show "12 plans / 47
        // schools using this module" badges without extra round-trips.
        $planCounts = DB::table('plan_modules')
            ->where('is_included', true)
            ->selectRaw('module_id, COUNT(*) as c')
            ->groupBy('module_id')->pluck('c', 'module_id');
        $overrideCounts = DB::table('school_module_overrides')
            ->selectRaw('module_id, COUNT(*) as c')
            ->groupBy('module_id')->pluck('c', 'module_id');

        return response()->json([
            'success' => true,
            'data'    => $modules->map(fn($m) => array_merge($m->toArray(), [
                'plans_using_count'    => (int) ($planCounts[$m->id] ?? 0),
                'schools_using_count'  => $this->countSchoolsWithModule($m->code),
                'override_rows_count'  => (int) ($overrideCounts[$m->id] ?? 0),
            ])),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code'        => 'required|string|max:64|unique:modules,code',
            'name'        => 'required|string|max:120',
            'description' => 'nullable|string|max:2000',
            'category'    => 'required|in:Platform,Academic,Finance,Communication,Operations,Mobile Apps',
            'icon'        => 'nullable|string|max:32',
            'surfaces'    => 'required|array',
            'surfaces.*'  => 'string|in:admin_web,teacher_app,student_app,parent_app',
            'is_addon'    => 'boolean',
            'is_core'     => 'boolean',
            'sort_order'  => 'integer|min:0|max:10000',
        ]);

        $module = Module::create($data);
        $this->audit->log($request->user()->id, 'module.create', 'module', $module->id, $data, $request);

        return response()->json(['success' => true, 'data' => $module], 201);
    }

    public function update(Request $request, $id)
    {
        $module = Module::findOrFail($id);

        $data = $request->validate([
            'name'        => 'sometimes|string|max:120',
            'description' => 'sometimes|nullable|string|max:2000',
            'category'    => 'sometimes|in:Platform,Academic,Finance,Communication,Operations,Mobile Apps',
            'icon'        => 'sometimes|nullable|string|max:32',
            'surfaces'    => 'sometimes|array',
            'surfaces.*'  => 'string|in:admin_web,teacher_app,student_app,parent_app',
            'is_addon'    => 'sometimes|boolean',
            'is_core'     => 'sometimes|boolean',
            'sort_order'  => 'sometimes|integer|min:0|max:10000',
            // We deliberately do NOT allow editing `code` — it's the
            // immutable identifier used across middleware and frontend
            // filter strings. Rename → create new code + migrate.
        ]);

        $module->update($data);
        $this->audit->log($request->user()->id, 'module.update', 'module', $module->id, $data, $request);

        return response()->json(['success' => true, 'data' => $module->fresh()]);
    }

    public function destroy(Request $request, $id)
    {
        $module = Module::findOrFail($id);

        $planUsage = DB::table('plan_modules')->where('module_id', $module->id)->count();
        $overrideUsage = DB::table('school_module_overrides')->where('module_id', $module->id)->count();

        if ($planUsage + $overrideUsage > 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete: this module is used by {$planUsage} plan(s) and {$overrideUsage} override(s). Remove those first.",
            ], 422);
        }

        $module->delete(); // soft delete
        $this->audit->log($request->user()->id, 'module.delete', 'module', $module->id, [], $request);

        return response()->json(['success' => true, 'message' => 'Module archived.']);
    }

    // -----------------------------------------------------------------
    //  PLAN COMPOSITION
    // -----------------------------------------------------------------

    public function planModules(Request $request, $planId)
    {
        $plan = Plan::findOrFail($planId);
        $included = $plan->modules()->wherePivot('is_included', true)->get(['modules.id','modules.code','modules.name']);
        return response()->json([
            'success' => true,
            'data'    => [
                'plan' => ['id' => $plan->id, 'name' => $plan->name, 'slug' => $plan->slug],
                'included_module_ids' => $included->pluck('id')->all(),
                'included_modules'    => $included,
            ],
        ]);
    }

    /**
     * Atomic replacement of the included module set for a plan, with
     * mandatory grandfather-existing-schools behaviour to prevent R7
     * (operator footgun: silently strip Attendance from 47 schools).
     *
     * Algorithm:
     *   1. Diff old vs new sets.
     *   2. For every REMOVED module, write a per-school
     *      `enabled` override for each school currently on this plan
     *      that was using that module — so their existing access is
     *      preserved. New schools picking this plan see the new set.
     *   3. Write the new pivot rows.
     *   4. Recompute affected schools' bitmaps.
     */
    public function updatePlanModules(Request $request, $planId)
    {
        $plan = Plan::findOrFail($planId);
        $data = $request->validate([
            'module_ids'           => 'required|array',
            'module_ids.*'         => 'integer|exists:modules,id',
            // operator must explicitly opt INTO stripping from existing
            // schools. Default = grandfather them via override.
            'strip_from_existing'  => 'sometimes|boolean',
            'reason'               => 'required|string|min:5|max:500',
        ]);

        $newSet = collect($data['module_ids'])->unique()->values();
        $oldSet = $plan->modules()->wherePivot('is_included', true)->pluck('modules.id');
        $removed = $oldSet->diff($newSet);
        $stripFromExisting = (bool) ($data['strip_from_existing'] ?? false);

        DB::transaction(function () use ($plan, $newSet, $removed, $stripFromExisting, $data, $request) {
            // Replace plan pivot atomically.
            DB::table('plan_modules')->where('plan_id', $plan->id)->delete();
            $now = now();
            $rows = $newSet->map(fn($mid) => [
                'plan_id'     => $plan->id,
                'module_id'   => $mid,
                'is_included' => true,
                'created_at'  => $now,
                'updated_at'  => $now,
            ])->all();
            if (!empty($rows)) DB::table('plan_modules')->insert($rows);

            // Grandfather existing schools by writing per-school
            // `enabled` overrides for each removed module.
            if (!$stripFromExisting && $removed->isNotEmpty()) {
                $schools = School::where('plan_id', $plan->id)->get();
                foreach ($schools as $school) {
                    foreach ($removed as $moduleId) {
                        SchoolModuleOverride::updateOrCreate(
                            ['school_id' => $school->id, 'module_id' => $moduleId],
                            [
                                'state' => 'enabled',
                                'reason' => 'Grandfather on plan composition change: ' . $data['reason'],
                                'expires_at' => null,
                                'granted_by' => $request->user()->id,
                            ]
                        );
                        // ^ boot hook on SchoolModuleOverride recomputes
                        // each affected school's bitmap.
                    }
                }
            } else {
                // We're stripping intentionally — just recompute every
                // school on this plan.
                ModuleAccessService::recomputeForPlan($plan->id);
            }
        });

        $this->audit->log($request->user()->id, 'plan.modules.update', 'plan', $plan->id, [
            'new_module_ids'      => $newSet->all(),
            'removed_module_ids'  => $removed->values()->all(),
            'strip_from_existing' => $stripFromExisting,
            'reason'              => $data['reason'],
        ], $request);

        return response()->json(['success' => true, 'message' => 'Plan composition updated.']);
    }

    /**
     * R7 mitigation: impact preview. Tells the operator how many
     * schools would be affected by REMOVING each module from this
     * plan, before they commit. Read-only.
     */
    public function planImpact(Request $request, $planId)
    {
        $plan = Plan::findOrFail($planId);
        $request->validate([
            'remove' => 'sometimes|array',
            'remove.*' => 'integer|exists:modules,id',
        ]);
        $removeIds = $request->input('remove', []);

        $schools = School::where('plan_id', $plan->id)->get(['id','name']);
        $impact = [];
        foreach ($removeIds as $mid) {
            $module = Module::find($mid);
            $impact[] = [
                'module_id'    => $mid,
                'module_code'  => $module?->code,
                'module_name'  => $module?->name,
                'affected_schools' => $schools->count(),
            ];
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'plan_id'        => $plan->id,
                'schools_count'  => $schools->count(),
                'remove_impact'  => $impact,
            ],
        ]);
    }

    // -----------------------------------------------------------------
    //  PER-SCHOOL OVERRIDES
    // -----------------------------------------------------------------

    public function schoolModules(Request $request, $schoolId)
    {
        $school = School::findOrFail($schoolId);
        $all = Module::orderBy('sort_order')->get();
        $codes = ModuleAccessService::for($school);
        $overrides = SchoolModuleOverride::where('school_id', $school->id)
            ->with('module:id,code,name')
            ->get()
            ->keyBy('module_id');

        $rows = $all->map(function (Module $m) use ($codes, $overrides, $school) {
            $enabled = $codes->contains($m->code);
            $ov = $overrides->get($m->id);
            $source = 'plan';
            if ($m->is_core) $source = 'core';
            elseif ($ov && $ov->isActive()) $source = 'override_' . $ov->state;
            elseif (!$enabled) $source = 'not_in_plan';
            return [
                'id'         => $m->id,
                'code'       => $m->code,
                'name'       => $m->name,
                'category'   => $m->category,
                'icon'       => $m->icon,
                'is_core'    => $m->is_core,
                'enabled'    => $enabled,
                'source'     => $source,
                'override'   => $ov ? [
                    'id'         => $ov->id,
                    'state'      => $ov->state,
                    'reason'     => $ov->reason,
                    'expires_at' => optional($ov->expires_at)->toIso8601String(),
                    'granted_by' => $ov->granted_by,
                ] : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => [
                'school'    => ['id' => $school->id, 'name' => $school->name],
                'plan'      => $school->plan ? ['id' => $school->plan->id, 'name' => $school->plan->name] : null,
                'modules'   => $rows,
            ],
        ]);
    }

    public function setOverride(Request $request, $schoolId)
    {
        $school = School::findOrFail($schoolId);
        $data = $request->validate([
            'module_id'   => 'required|integer|exists:modules,id',
            'state'       => 'required|in:enabled,disabled',
            'reason'      => 'required|string|min:5|max:500',
            'expires_at'  => 'nullable|date|after:now',
        ]);

        // Block disabling a module that other enabled modules depend on
        // (R2 mitigation — broken cross-module data).
        if ($data['state'] === 'disabled') {
            $module = Module::with('dependents')->find($data['module_id']);
            $enabledCodes = ModuleAccessService::for($school);
            $blockingDependents = $module->dependents
                ->filter(fn($d) => $enabledCodes->contains($d->code))
                ->pluck('name')->all();
            if (!empty($blockingDependents)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot disable: still required by ' . implode(', ', $blockingDependents) . '. Disable those first.',
                ], 422);
            }
        }

        $override = SchoolModuleOverride::updateOrCreate(
            ['school_id' => $school->id, 'module_id' => $data['module_id']],
            [
                'state'      => $data['state'],
                'reason'     => $data['reason'],
                'expires_at' => $data['expires_at'] ?? null,
                'granted_by' => $request->user()->id,
            ]
        ); // boot hook recomputes school bitmap

        $this->audit->log($request->user()->id, 'school.module.override', 'school', $school->id, [
            'module_id' => $data['module_id'],
            'state'     => $data['state'],
            'reason'    => $data['reason'],
            'expires_at' => $data['expires_at'] ?? null,
        ], $request);

        return response()->json(['success' => true, 'data' => $override->fresh(['module'])]);
    }

    public function clearOverride(Request $request, $schoolId, $moduleId)
    {
        $override = SchoolModuleOverride::where('school_id', $schoolId)
            ->where('module_id', $moduleId)
            ->firstOrFail();

        $override->delete(); // boot hook recomputes
        $this->audit->log($request->user()->id, 'school.module.override.clear', 'school', $schoolId, [
            'module_id' => $moduleId,
        ], $request);

        return response()->json(['success' => true, 'message' => 'Override cleared.']);
    }

    // -----------------------------------------------------------------
    //  ACCESS LOGS
    // -----------------------------------------------------------------

    public function accessLogs(Request $request)
    {
        $logs = DB::table('module_access_logs')
            ->leftJoin('schools', 'schools.id', '=', 'module_access_logs.school_id')
            ->leftJoin('modules', 'modules.id', '=', 'module_access_logs.module_id')
            ->select(
                'module_access_logs.id',
                'module_access_logs.outcome',
                'module_access_logs.denied_reason',
                'module_access_logs.route',
                'module_access_logs.user_id',
                'module_access_logs.created_at',
                'schools.name as school_name',
                'schools.id as school_id',
                'modules.code as module_code',
                'modules.name as module_name'
            )
            ->when($request->school_id, fn($q, $v) => $q->where('module_access_logs.school_id', $v))
            ->when($request->module_id, fn($q, $v) => $q->where('module_access_logs.module_id', $v))
            ->orderByDesc('module_access_logs.created_at')
            ->limit(200)
            ->get();

        return response()->json(['success' => true, 'data' => $logs]);
    }

    private function countSchoolsWithModule(string $code): int
    {
        // Schools whose cached bitmap contains the code. Bitmap is a
        // space-separated string; SQL LIKE with word-boundary delimiters.
        return School::whereNotNull('module_cache_bitmap')
            ->where(function ($q) use ($code) {
                $q->where('module_cache_bitmap', 'like', $code)
                  ->orWhere('module_cache_bitmap', 'like', "{$code} %")
                  ->orWhere('module_cache_bitmap', 'like', "% {$code} %")
                  ->orWhere('module_cache_bitmap', 'like', "% {$code}");
            })
            ->count();
    }
}
