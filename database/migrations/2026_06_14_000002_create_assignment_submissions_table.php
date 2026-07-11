<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `assignment_submissions` — student-uploaded PDFs against an
 * assignment (a `homework` row with kind='assignment').
 *
 * Why a separate table:
 *   - One assignment can have many submissions (one per student in
 *     the class). 1-to-many → its own table, not a column.
 *   - Submissions carry their own lifecycle (submitted, graded,
 *     resubmitted) independent of the parent homework row.
 *   - PDFs are large and live in S3. We store the *key* / public URL
 *     here, not the bytes.
 *
 * Storage convention (set by StudentAssignmentController):
 *   schools/{school_id}/assignments/{homework_id}/{student_id}-{ts}.pdf
 *
 * Uniqueness: one ACTIVE submission per (homework, student). We
 * enforce this with a unique index; resubmission is implemented by
 * overwriting the existing row + soft-history via `previous_path`.
 * (For now we just overwrite — version history can come later.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('assignment_submissions')) return;

        Schema::create('assignment_submissions', function (Blueprint $table) {
            $table->id();

            // Multi-tenant root. Every query MUST filter by
            // school_id; the FK alone isn't enough since callers
            // sometimes use scopes/closures that bypass relations.
            $table->unsignedBigInteger('school_id');

            // FK to homework row (kind must be 'assignment' — enforced
            // at the controller level, not the schema, so we don't
            // need a CHECK constraint that Postgres-only).
            $table->unsignedBigInteger('homework_id');

            // The submitting student. NOT the login user — this is
            // the students table id, same convention as the rest of
            // the student-facing tables.
            $table->unsignedBigInteger('student_id');

            // S3 key (e.g. schools/12/assignments/45/3-1718380000.pdf).
            // We store the bare key so we can regenerate URLs (with
            // expiry / signing) on the fly. `file_url` carries the
            // last-issued public URL purely as a perf shortcut.
            $table->string('file_path');
            $table->string('file_url')->nullable();
            $table->string('file_name')->nullable();   // original filename for display
            $table->unsignedInteger('file_size')->nullable(); // bytes — UI nicety
            $table->string('mime_type', 64)->nullable();

            // Lifecycle timestamps. submitted_at is set on upload
            // (may differ from created_at if a resubmit replaces the
            // row in place). graded_at + graded_by are nullable
            // until the teacher acts.
            $table->timestamp('submitted_at')->nullable();
            $table->decimal('marks', 6, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->unsignedBigInteger('graded_by')->nullable(); // users.id
            $table->timestamp('graded_at')->nullable();

            $table->timestamps();

            $table->index('school_id');
            $table->index('homework_id');
            $table->index('student_id');
            // One active submission per (homework, student). Resubmits
            // overwrite this row rather than creating a new one.
            $table->unique(['homework_id', 'student_id'], 'as_homework_student_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_submissions');
    }
};
