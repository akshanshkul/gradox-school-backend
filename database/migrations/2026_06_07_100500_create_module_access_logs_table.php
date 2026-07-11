<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit / observability log for module access events.
 *
 * We log ONLY denied events to keep volume manageable — allowed events
 * happen on every request and would balloon the table. Denied events
 * are interesting because:
 *
 *   1. Support can answer "why does my school not have X?" tickets in
 *      under a minute via the SaaS-admin /access-logs page.
 *   2. Product can identify modules that are frequently being denied
 *      across many schools — candidates for tier promotion.
 *   3. Sales can spot single schools hitting many denials — likely
 *      upgrade conversations.
 *   4. Engineering can spot anomalies — sudden spike of denials on a
 *      module that should be enabled = misconfigured override.
 *
 * Retention: a daily prune deletes rows older than 30 days (driven by
 * the indexed `created_at`). Beyond 30 days, denials are stats not
 * incidents — anything chronic gets surfaced sooner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('module_id')->nullable()
                ->constrained('modules')->nullOnDelete();
            // Nullable because some denied paths happen pre-auth
            // (e.g. public school landing fetching a gated bit).
            $table->foreignId('user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('route', 255);
            $table->enum('outcome', ['allowed', 'denied'])->default('denied');
            // Why was it denied? Strings keep this readable in raw SQL
            // for ops without joining a lookup table:
            //   not_in_plan / overridden_disabled / expired_grant /
            //   missing_school / unknown_module
            $table->string('denied_reason', 64)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            // Query patterns we want fast:
            //   "logs for this school in date range"
            //   "logs for this module across all schools"
            $table->index(['school_id', 'created_at']);
            $table->index(['module_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_access_logs');
    }
};
