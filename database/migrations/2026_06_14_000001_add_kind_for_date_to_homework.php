<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Homework feature split — homework vs assignment.
 *
 * Originally the `homework` table treated every row as having a
 * mandatory `due_date`. Product direction now wants two distinct
 * shapes:
 *
 *   1. HOMEWORK — short text the teacher posts FOR a particular day
 *      ("Tonight's reading: chapter 4"). No submission. Grouped
 *      day-wise in the student app via the new `for_date` column.
 *
 *   2. ASSIGNMENT — longer brief with a `due_date` and a PDF
 *      submission flow (see create_assignment_submissions_table).
 *
 * This migration adds:
 *   - `kind` enum('homework','assignment') — defaults to 'homework'
 *     for new inserts; legacy rows are backfilled below.
 *   - `for_date` nullable date — the calendar day the homework is
 *     intended for. Used by the day-wise grouping on the student
 *     app. NULL is allowed because legacy/imported rows may not
 *     carry one.
 *   - `due_date` becomes nullable — only assignments need it.
 *
 * Backfill rule for existing rows (all of which currently have a
 * non-null due_date):
 *   - kind     = 'homework'   (preserves current UX — they appear
 *                               in the homework feed, not the new
 *                               Assignments tab the user hasn't
 *                               opted in to yet)
 *   - for_date = due_date     (so the day-wise view still buckets
 *                               them correctly)
 *
 * We do NOT mark legacy rows as 'assignment' because that would
 * surface them in a brand-new "Assignments" tab that expects PDF
 * submissions — students would see assignments they couldn't have
 * submitted. Leaving them as homework is the zero-surprise default.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('homework')) {
            return; // base migration not run yet — nothing to alter
        }

        Schema::table('homework', function (Blueprint $table) {
            if (!Schema::hasColumn('homework', 'kind')) {
                // `enum` is portable to MySQL; on Postgres Laravel
                // emits a CHECK constraint. The default keeps inserts
                // backward-compatible for callers that haven't
                // started sending the new field yet.
                $table->enum('kind', ['homework', 'assignment'])
                    ->default('homework')
                    ->after('subject_id');
            }
            if (!Schema::hasColumn('homework', 'for_date')) {
                $table->date('for_date')->nullable()->after('description');
            }
        });

        // Make `due_date` nullable. Wrap in a separate ALTER so the
        // column-add migrations above land first (Postgres can balk
        // at mixing add + alter in one closure).
        if (Schema::hasColumn('homework', 'due_date')) {
            Schema::table('homework', function (Blueprint $table) {
                $table->date('due_date')->nullable()->change();
            });
        }

        // Backfill — every legacy row gets `kind='homework'` and
        // `for_date=due_date`. Idempotent: only touches rows where
        // the new columns are still NULL / default.
        // Using a raw update because Eloquent's `update` here would
        // trigger model events on rows we haven't loaded.
        DB::table('homework')
            ->whereNull('for_date')
            ->whereNotNull('due_date')
            ->update(['for_date' => DB::raw('due_date')]);

        // The enum default already covers `kind`, but make it
        // explicit for any row that somehow has NULL (older
        // sqlite-in-test databases occasionally leak this through).
        DB::table('homework')->whereNull('kind')->update(['kind' => 'homework']);

        // Helpful for the day-wise grouping query on the student app
        // — single composite index covers (school_class_id, for_date).
        // Skip if the index already exists from a prior run.
        try {
            Schema::table('homework', function (Blueprint $table) {
                $table->index(['school_class_id', 'for_date'], 'homework_class_for_date_idx');
            });
        } catch (\Throwable $e) {
            // Index probably exists — non-fatal.
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('homework')) return;

        try {
            Schema::table('homework', function (Blueprint $table) {
                $table->dropIndex('homework_class_for_date_idx');
            });
        } catch (\Throwable $e) {
            // ignore
        }

        Schema::table('homework', function (Blueprint $table) {
            if (Schema::hasColumn('homework', 'for_date')) {
                $table->dropColumn('for_date');
            }
            if (Schema::hasColumn('homework', 'kind')) {
                $table->dropColumn('kind');
            }
        });

        // We deliberately do NOT revert `due_date` to NOT NULL on
        // rollback — by the time we'd roll back, real assignment
        // rows might exist with NULL due_date set elsewhere.
    }
};
