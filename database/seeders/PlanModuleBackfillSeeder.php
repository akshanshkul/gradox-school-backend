<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Plan;
use App\Models\School;
use App\Services\ModuleAccessService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Phase A backfill — the entire point of which is:
 *   "Don't break a single existing school during rollout."
 *
 * Strategy:
 *   1. For every plan in the catalog, enable EVERY module from the
 *      catalog. This is the permissive default — schools see exactly
 *      what they saw yesterday because every module is available.
 *   2. Recompute every school's cached module bitmap so the
 *      EnsureModuleAvailable middleware (which reads the cached
 *      bitmap on every gated request) has correct data from minute
 *      one of Phase A deploy.
 *
 * Phase C (the operator UI) is where the SaaS admin will then start
 * tightening — at that point they get an impact preview before each
 * change. This seeder is the safety net before that.
 *
 * Idempotent: re-running upserts rows without duplication; uses
 * updateOrCreate-via-DB pattern that survives even a partial run.
 */
class PlanModuleBackfillSeeder extends Seeder
{
    public function run(): void
    {
        $modules = Module::pluck('id')->all();
        $plans   = Plan::pluck('id')->all();

        if (empty($modules) || empty($plans)) {
            $this->command?->warn('No modules or no plans seeded yet — skipping backfill.');
            return;
        }

        // ----- 1. Plan ↔ Module pivot: every plan gets every module -----
        $now = now();
        $rows = [];
        foreach ($plans as $planId) {
            foreach ($modules as $moduleId) {
                $rows[] = [
                    'plan_id'     => $planId,
                    'module_id'   => $moduleId,
                    'is_included' => true,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
            }
        }
        // upsert handles re-runs cleanly — duplicate (plan_id, module_id)
        // updates is_included rather than throwing.
        DB::table('plan_modules')->upsert(
            $rows,
            ['plan_id', 'module_id'],
            ['is_included', 'updated_at']
        );

        $this->command?->info('Plan-module pivot backfilled: '
            . count($plans) . ' plans × ' . count($modules) . ' modules = '
            . count($rows) . ' rows.');

        // ----- 2. Recompute every school's cached bitmap -----
        // This is the table-stakes for the middleware to work
        // correctly the moment Phase A is deployed. Without this, the
        // first request to a gated endpoint for each school would
        // lazy-seed the bitmap (which is functional but slow).
        $schoolCount = 0;
        School::chunkById(100, function ($schools) use (&$schoolCount) {
            foreach ($schools as $school) {
                ModuleAccessService::recompute($school);
                $schoolCount++;
            }
        });

        $this->command?->info("School bitmaps recomputed: {$schoolCount} schools.");
    }
}
