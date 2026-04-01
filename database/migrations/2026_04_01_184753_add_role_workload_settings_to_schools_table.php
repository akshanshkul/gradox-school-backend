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
            $table->integer('teacher_min')->default(2);
            $table->integer('teacher_max')->default(6);
            $table->integer('incharge_min')->default(1);
            $table->integer('incharge_max')->default(4);
            $table->integer('admin_min')->default(0);
            $table->integer('admin_max')->default(2);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn([
                'teacher_min', 'teacher_max', 
                'incharge_min', 'incharge_max', 
                'admin_min', 'admin_max'
            ]);
        });
    }
};
