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
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('admission_number');
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->date('date_of_birth');
            $table->date('admission_date')->nullable();
            
            // Guardian Info
            $table->string('parent_name')->nullable();
            $table->string('parent_phone')->nullable();
            $table->string('parent_occupation')->nullable();
            $table->text('address')->nullable();
            $table->string('previous_school')->nullable();
            $table->string('tc_details')->nullable();
            
            $table->string('photo_path')->nullable();
            $table->string('status')->default('active'); // active, inactive, former
            $table->timestamps();

            $table->unique(['school_id', 'admission_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
