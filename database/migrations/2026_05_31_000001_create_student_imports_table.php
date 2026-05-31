<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Header table for bulk student-import jobs. One row per uploaded file.
 * Detailed per-row data lives in student_import_rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_imports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('uploaded_by_user_id')->index();

            $table->string('original_filename');
            $table->string('stored_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('mime_type', 100)->nullable();

            // Lifecycle: parsed -> committing -> committed | failed | cancelled
            $table->string('status', 24)->default('parsed')->index();

            // Aggregated counts re-computed every time validation runs.
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('warning_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);

            // Pre-commit master-data deltas the admin must approve before commit.
            // { new_sessions: [...], new_classes: [...], new_sections: [...] }
            $table->json('master_data_delta')->nullable();

            // Distribution charts payload (by class / section / gender / fee category etc.)
            $table->json('distribution')->nullable();

            // Free-form audit info — admin who committed, when, how long, last error, etc.
            $table->json('commit_meta')->nullable();

            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_imports');
    }
};
