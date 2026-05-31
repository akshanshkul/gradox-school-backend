<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-row staging for a student-import job. Rows survive across edits so
 * the admin can repeatedly tweak, revalidate, and finally commit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_import_rows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_import_id')->index();

            // 1-based row position in the source file (for the UI to show "Row 25 …").
            $table->unsignedInteger('row_number');

            // Raw cell values as read from the spreadsheet (header => string).
            $table->json('raw_data');

            // After normalization: cleaned values + resolved master IDs where applicable.
            // Example: { gender: "male", session_id: 12, school_class_id: 34, ... }
            $table->json('normalized_data')->nullable();

            // Row-level validation results.
            // errors blocks import, warnings don't.
            $table->json('errors')->nullable();    // [{ field, code, message, suggestion }]
            $table->json('warnings')->nullable();

            // Result of the duplicate-detection sweep.
            // null if no match. Otherwise contains the matched student id + reason.
            $table->json('duplicate_match')->nullable();
            $table->unsignedBigInteger('duplicate_of_student_id')->nullable()->index();

            // Status used to color the row in the UI: valid | warning | error
            $table->string('status', 16)->default('error')->index();

            // What to do at commit time: create | update | skip
            $table->string('action', 16)->default('create');

            // After commit succeeds for THIS row, store the resulting student id
            // so we have a clear audit trail and can detect re-runs.
            $table->unsignedBigInteger('committed_student_id')->nullable();
            $table->timestamp('committed_at')->nullable();

            $table->timestamps();

            $table->index(['student_import_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_import_rows');
    }
};
