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
        Schema::table('timetable_entries', function (Blueprint $table) {
            $table->date('date')->nullable()->after('classroom_id')->index();
            $table->string('day_of_week')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('timetable_entries', function (Blueprint $table) {
            $table->dropIndex(['date']);
            $table->dropColumn('date');
            $table->string('day_of_week')->nullable(false)->change();
        });
    }
};
