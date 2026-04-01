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
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn([
                'teacher_min', 
                'teacher_max', 
                'incharge_min', 
                'incharge_max', 
                'admin_min', 
                'admin_max'
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'min_classes_per_day',
                'max_classes_per_day'
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->integer('teacher_min')->nullable();
            $table->integer('teacher_max')->nullable();
            $table->integer('incharge_min')->nullable();
            $table->integer('incharge_max')->nullable();
            $table->integer('admin_min')->nullable();
            $table->integer('admin_max')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->integer('min_classes_per_day')->nullable();
            $table->integer('max_classes_per_day')->nullable();
        });
    }
};
