<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * student_logins originally had GLOBAL unique constraints on
 *   - admission_number
 *   - email
 *
 * That blocks School B from issuing a login for admission_number "100001"
 * when School A already has one — even though the corresponding
 * students.unique IS school-scoped. This migration:
 *
 *   1. Adds a `school_id` column (derived from students.school_id)
 *   2. Backfills it from the related student row
 *   3. Drops the global unique indexes
 *   4. Re-adds them as composite (school_id, admission_number) /
 *      (school_id, email)
 *
 * Schools can now share admission numbers and emails across tenants without
 * cross-school collisions.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('student_logins', 'school_id')) {
            Schema::table('student_logins', function (Blueprint $table) {
                $table->unsignedBigInteger('school_id')->nullable()->after('id');
            });
        }

        // Backfill from related student
        DB::statement('UPDATE student_logins sl
                       JOIN students s ON s.id = sl.student_id
                       SET sl.school_id = s.school_id
                       WHERE sl.school_id IS NULL');

        // Drop global uniques (gracefully — they were named after Laravel's defaults)
        Schema::table('student_logins', function (Blueprint $table) {
            try { $table->dropUnique('student_logins_admission_number_unique'); } catch (\Throwable $e) { /* already gone */ }
            try { $table->dropUnique('student_logins_email_unique'); } catch (\Throwable $e) { /* already gone */ }
        });

        // Add school-scoped composite uniques
        Schema::table('student_logins', function (Blueprint $table) {
            $table->unique(['school_id', 'admission_number'], 'student_logins_school_admission_unique');
            $table->unique(['school_id', 'email'], 'student_logins_school_email_unique');

            $table->index('school_id');
        });
    }

    public function down(): void
    {
        Schema::table('student_logins', function (Blueprint $table) {
            try { $table->dropUnique('student_logins_school_admission_unique'); } catch (\Throwable $e) {}
            try { $table->dropUnique('student_logins_school_email_unique'); } catch (\Throwable $e) {}
            try { $table->dropIndex(['school_id']); } catch (\Throwable $e) {}
        });

        // Restore original global uniques
        Schema::table('student_logins', function (Blueprint $table) {
            $table->unique('admission_number');
            $table->unique('email');
        });

        // Leave the school_id column — dropping it after rollback is risky
        // because production tables may have come to rely on it. If you want
        // to drop it, do so in a separate manual migration.
    }
};
