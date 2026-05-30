<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Drop JSON columns if they exist in class_subject pivot table
        Schema::table('class_subject', function (Blueprint $table) {
            if (Schema::hasColumn('class_subject', 'notes')) {
                $table->dropColumn('notes');
            }
            if (Schema::hasColumn('class_subject', 'syllabus')) {
                $table->dropColumn('syllabus');
            }
        });

        // 2. Create class_subject_notes table
        Schema::create('class_subject_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_subject_id')->constrained('class_subject')->onDelete('cascade');
            $table->string('title');
            $table->string('file_url')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // 3. Create class_subject_syllabus table
        Schema::create('class_subject_syllabus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_subject_id')->constrained('class_subject')->onDelete('cascade');
            $table->string('topic');
            $table->text('description')->nullable();
            $table->string('status')->default('pending'); // pending, in-progress, completed
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('class_subject_syllabus');
        Schema::dropIfExists('class_subject_notes');

        Schema::table('class_subject', function (Blueprint $table) {
            $table->json('notes')->nullable();
            $table->json('syllabus')->nullable();
        });
    }
};
