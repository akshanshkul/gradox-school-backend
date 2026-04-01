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
        Schema::table('school_classes', function (Blueprint $table) {
            $table->foreignId('class_teacher_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('default_classroom_id')->nullable()->constrained('classrooms')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropForeign(['class_teacher_id']);
            $table->dropForeign(['default_classroom_id']);
            $table->dropColumn(['class_teacher_id', 'default_classroom_id']);
        });
    }
};
