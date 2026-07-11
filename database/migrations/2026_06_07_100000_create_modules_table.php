<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Module Catalog — the universe of features the platform offers.
 *
 * Each row is one feature that can be turned on/off per plan or per
 * school. Examples: `attendance`, `fees`, `homework`, `online_classes`.
 * Records are seeded at deploy time (see ModuleCatalogSeeder) and
 * thereafter managed by SaaS admin via the /platform/modules UI.
 *
 *   - `code` is the immutable machine identifier referenced by
 *     middleware, frontend filters, and the module_dependencies pivot.
 *     NEVER renamed after a module is in production use.
 *   - `is_core` flags modules that MUST be reachable for the platform
 *     to work at all (login, dashboard, settings, profile). Core
 *     modules are exempt from middleware gating — they always allow.
 *   - `surfaces` is a JSON array of which apps display this module
 *     ("admin_web", "teacher_app", "student_app", "parent_app"). Used
 *     by each app to know whether to render an entry for it.
 *   - `is_addon` allows a module to be granted standalone to schools
 *     on a lower plan (e.g. school on Basic buys WhatsApp Alerts).
 *   - Soft-delete on purpose: if a module is referenced by any plan or
 *     override the SaaS admin UI refuses to permanently delete it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->enum('category', [
                'Platform', 'Academic', 'Finance', 'Communication', 'Operations', 'Mobile Apps'
            ])->index();
            $table->string('icon', 32)->nullable();
            $table->json('surfaces');
            $table->boolean('is_addon')->default(false);
            $table->boolean('is_core')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
