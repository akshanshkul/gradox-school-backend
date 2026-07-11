<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan ↔ Module pivot. Defines which modules each subscription plan
 * includes by default.
 *
 *   - `is_included = true` is the normal case (this plan includes
 *     this module).
 *   - `is_included = false` lets SaaS admin explicitly EXCLUDE a
 *     module from a plan that would otherwise inherit it from a macro
 *     (when we add tier macros later). For the initial seeder, only
 *     true rows are written.
 *
 * The backfill seeder writes a row for EVERY (plan × module) pair
 * with is_included=true so the initial Phase A rollout makes zero
 * visible change — every school keeps full access to every module.
 * Phase C is when an operator starts curating these.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->foreignId('module_id')->constrained('modules')->cascadeOnDelete();
            $table->boolean('is_included')->default(true);
            $table->timestamps();

            $table->unique(['plan_id', 'module_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_modules');
    }
};
