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
        // 1. Data Repair: Ensure no student is "orphaned" by a mismatching section_id
        // We trust school_class_id as the primary link.
        DB::table('student_academic_records')
            ->join('school_classes', 'student_academic_records.school_class_id', '=', 'school_classes.id')
            ->update(['student_academic_records.section_id' => DB::raw('school_classes.section_id')]);

        // 2. Drop the redundant column
        Schema::table('student_academic_records', function (Blueprint $table) {
            $table->dropForeign(['section_id']);
            $table->dropColumn('section_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_academic_records', function (Blueprint $table) {
            $table->unsignedBigInteger('section_id')->nullable()->after('school_class_id');
            $table->foreign('section_id')->references('id')->on('sections')->onDelete('set null');
        });

        // Re-populate if possible
        DB::table('student_academic_records')
            ->join('school_classes', 'student_academic_records.school_class_id', '=', 'school_classes.id')
            ->update(['student_academic_records.section_id' => DB::raw('school_classes.section_id')]);
    }
};
