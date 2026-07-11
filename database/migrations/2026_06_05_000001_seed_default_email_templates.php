<?php

use Illuminate\Database\Migrations\Migration;
use Database\Seeders\EmailTemplateSeeder;

/**
 * Idempotently re-seeds the system email templates (school_id = NULL).
 *
 * Why a migration calling a seeder, not a plain INSERT statement here:
 *   - The seeder uses `updateOrCreate` keyed on (slug, school_id=null),
 *     so this can run repeatedly without producing duplicates.
 *   - Whenever a new system template is added, we just add it to the seeder
 *     and re-run this migration via the next deploy (or via
 *     `php artisan db:seed --class=EmailTemplateSeeder` for hot updates).
 *   - Keeps templates source-of-truth in one place (the seeder), instead of
 *     copy-pasted across multiple migrations.
 *
 * What this guarantees:
 *   - Fresh installs (`migrate:fresh`) end with the templates in place.
 *   - Existing prod servers running `php artisan migrate` on deploy get
 *     them too, even if the original seeder was never called.
 *   - Every school — existing or future — sees the system templates via
 *     EmailTemplateController@index's fallback (school_id IS NULL OR
 *     school_id = current school). Schools that have customized a template
 *     keep their override; everyone else sees the default.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Defensive: only run if the table exists. (It should — created
        // by an earlier migration — but `migrate:fresh` ordering means we
        // shouldn't assume it.)
        if (!\Schema::hasTable('email_templates')) {
            return;
        }
        (new EmailTemplateSeeder())->run();
    }

    public function down(): void
    {
        // Removing the system templates would break every school's email
        // sending — leave them in on rollback. If someone really wants to
        // purge, they can do it manually:
        //   App\Models\EmailTemplate::whereNull('school_id')->delete();
    }
};
