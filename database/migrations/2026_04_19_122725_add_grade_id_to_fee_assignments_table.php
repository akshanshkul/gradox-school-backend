<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_assignments', function (Blueprint $table) {
            $table->foreignId('grade_id')->nullable()->after('session_id')->constrained()->onDelete('cascade');
            $table->index(['school_id', 'grade_id', 'session_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fee_assignments', function (Blueprint $table) {
            $table->dropForeign(['fee_assignments_grade_id_foreign']); // Standard Laravel foreign key name
            $table->dropColumn('grade_id');
        });
    }
};
