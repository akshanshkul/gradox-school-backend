<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dependency graph for modules.
 *
 * Example: `report_cards` depends on `exams`, which depends on
 * `academic_structure`. The SaaS-admin UI uses this to:
 *
 *   - Refuse to disable a module when modules depending on it are
 *     still enabled (forces a top-down disable order).
 *   - Refuse to enable a module when its dependencies aren't enabled
 *     (forces a bottom-up enable order).
 *   - Show a confirmation cascade: "Disabling Attendance will also
 *     disable Advanced Analytics. Confirm?"
 *
 * Self-references are forbidden by app-level validation. Cycles are
 * structurally impossible if we enforce that depends_on_module_id
 * must already exist when a row is inserted — but we don't put a DB
 * constraint on cycle prevention; the catalog is small enough that
 * the seeder + UI keep it acyclic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained('modules')->cascadeOnDelete();
            $table->foreignId('depends_on_module_id')->constrained('modules')->cascadeOnDelete();
            $table->timestamps();

            // One edge per (module, dependency) pair; the same dependency
            // can't be listed twice for the same module.
            $table->unique(['module_id', 'depends_on_module_id'], 'module_dep_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_dependencies');
    }
};
