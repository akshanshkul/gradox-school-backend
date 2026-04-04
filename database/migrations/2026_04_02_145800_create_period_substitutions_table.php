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
        Schema::create('period_substitutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('timetable_entry_id')->constrained()->onDelete('cascade');
            $table->foreignId('substitute_teacher_id')->constrained('users')->onDelete('cascade');
            $table->date('date');
            $table->enum('reason', ['absence', 'half_day', 'official_duty', 'other'])->default('absence');
            $table->text('remarks')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Ensure a period is only substituted once per day
            $table->unique(['timetable_entry_id', 'date'], 'unique_substitution');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('period_substitutions');
    }
};
