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
        // 1. Exam Types (Unit Test, Mid Term, Final, Unit Test 1)
        Schema::create('exam_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        // 2. Exam Terms (Term 1, Annual)
        Schema::create('exam_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('sessions')->cascadeOnDelete();
            $table->string('name');
            $table->decimal('weightage', 5, 2)->default(100.00)->comment('Contribution to session total %');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 3. Grading Scales
        Schema::create('grading_scales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->decimal('min_percent', 5, 2);
            $table->decimal('max_percent', 5, 2);
            $table->string('grade'); // A+, A, B, C
            $table->decimal('grade_point', 3, 2)->default(0.00); // 4.0, 3.5
            $table->string('description')->nullable(); // Excellent, Good
            $table->timestamps();
        });

        // 4. Exam Structures (Links Class + Subject + Term + Type)
        Schema::create('exam_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->enum('scoring_type', ['marks', 'grade'])->default('marks');
            $table->integer('passing_marks')->default(33);
            $table->timestamps();
            
            $table->unique(['exam_term_id', 'exam_type_id', 'school_class_id', 'subject_id'], 'unique_exam_config');
        });

        // 5. Exam Structure Components (Theory, Practical, Viva)
        Schema::create('exam_structure_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_structure_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // Theory, Practical, Attendance
            $table->integer('max_marks');
            $table->timestamps();
        });

        // 6. Student Exam Marks
        Schema::create('student_exam_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_structure_id')->constrained()->cascadeOnDelete();
            $table->json('component_marks')->comment('Format: {"Theory": 65, "Practical": 28}');
            $table->decimal('total_obtained', 8, 2)->nullable();
            $table->string('grade_obtained')->nullable();
            $table->string('attendance_status')->default('present'); // present, absent, sick, exempt
            $table->text('teacher_remarks')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'exam_structure_id']);
        });

        // 7. Scholastic / Co-Scholastic Assessments
        Schema::create('scholastic_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('sessions')->cascadeOnDelete();
            $table->string('category'); // Discipline, Arts, Leadership
            $table->string('grade'); // A - E
            $table->timestamps();

            $table->unique(['student_id', 'session_id', 'category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scholastic_assessments');
        Schema::dropIfExists('student_exam_marks');
        Schema::dropIfExists('exam_structure_components');
        Schema::dropIfExists('exam_structures');
        Schema::dropIfExists('grading_scales');
        Schema::dropIfExists('exam_terms');
        Schema::dropIfExists('exam_types');
    }
};
