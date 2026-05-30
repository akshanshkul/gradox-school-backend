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
        if (!Schema::hasTable('homework')) {
            Schema::create('homework', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id');
                $table->unsignedBigInteger('created_by');
                $table->unsignedBigInteger('school_class_id');
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->string('title');
                $table->text('description')->nullable();
                $table->date('due_date');
                $table->string('status')->default('active'); // active, archived
                $table->timestamps();
                
                $table->index('school_id');
                $table->index('school_class_id');
                $table->index('created_by');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('homework');
    }
};
