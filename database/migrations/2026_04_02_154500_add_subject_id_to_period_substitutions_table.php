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
        Schema::table('period_substitutions', function (Blueprint $table) {
            $table->foreignId('substitute_subject_id')->nullable()->constrained('subjects')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('period_substitutions', function (Blueprint $table) {
            $table->dropForeign(['substitute_subject_id']);
            $table->dropColumn('substitute_subject_id');
        });
    }
};
