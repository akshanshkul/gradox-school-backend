<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cached effective-module set on each school row, for O(1) lookups.
 *
 * The hot path of module-access checks happens on EVERY request that
 * touches a gated endpoint. JOIN-ing plans + plan_modules + overrides
 * per request would add latency on every single API call. Instead we
 * cache the resolved module-code set right on the school row.
 *
 *   - `module_cache_bitmap` — space-separated list of enabled module
 *     codes, e.g. "attendance fees exams homework". A single column
 *     read; PHP splits by space; in-set check is O(1) via hash lookup.
 *     Chose a string column over JSON for cheaper read serialization;
 *     codes are short and the list is small (<30 modules).
 *
 *   - `module_cache_version` — bumped every time the resolved set
 *     changes. Per-request in-memory caches use this to know when to
 *     invalidate without a DB hit.
 *
 * Rebuilds happen synchronously inside transactions that:
 *   - change school.plan_id
 *   - insert/update/delete a school_module_overrides row
 *   - insert/update/delete a plan_modules row (cascades to all schools
 *     on that plan)
 *   - modify the modules catalog (cascades to all schools using it)
 *
 * See ModuleAccessService::recompute() for the rebuild logic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->text('module_cache_bitmap')->nullable()->after('plan_id');
            $table->unsignedInteger('module_cache_version')->default(0)->after('module_cache_bitmap');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['module_cache_bitmap', 'module_cache_version']);
        });
    }
};
