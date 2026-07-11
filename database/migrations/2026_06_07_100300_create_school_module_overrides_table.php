<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-school module overrides — the "gift" or "temporary disable" lever.
 *
 * The default behaviour: a school's enabled modules = its plan's
 * modules. When SaaS admin wants to deviate from that for a specific
 * school (e.g. gift Online Classes free during their pilot, or
 * temporarily disable a broken module), they write a row here.
 *
 *   - `state = 'enabled'`  → add this module on top of plan defaults
 *   - `state = 'disabled'` → remove this module from plan defaults
 *
 * `expires_at` is the auto-revert date. The modules:expire-overrides
 * scheduled command (runs daily 04:00) finds rows past their expiry,
 * deletes them, recomputes the school's module bitmap, and emails the
 * school admin a heads-up.
 *
 * `reason` is mandatory at the app layer (saas-admin form validation,
 * not a DB constraint, because empty string is allowed in SQL but the
 * controller enforces min:10 chars). It writes audit history that
 * support can pull up when answering "why does my school not have X?"
 *
 * The (school_id, module_id) unique constraint means a school can
 * have at most ONE active override per module — granting again
 * overwrites (no override stacking).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_module_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('module_id')->constrained('modules')->cascadeOnDelete();
            $table->enum('state', ['enabled', 'disabled']);
            $table->text('reason');
            $table->timestamp('expires_at')->nullable()->index();
            // Nullable so a system-generated override (e.g. trial
            // extension automatically granting Standard modules) can
            // exist without a human attribution.
            $table->foreignId('granted_by')->nullable()
                ->constrained('platform_admins')->nullOnDelete();
            $table->timestamps();

            $table->unique(['school_id', 'module_id']);
            // Quick lookup for the "what's enabled for this school"
            // query without scanning the whole table.
            $table->index(['school_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_module_overrides');
    }
};
