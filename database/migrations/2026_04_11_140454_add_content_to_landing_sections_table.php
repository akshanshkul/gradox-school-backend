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
        Schema::table('landing_sections', function (Blueprint $table) {
            $table->longText('content')->nullable();
            $table->boolean('is_visible')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('landing_sections', function (Blueprint $table) {
            $table->dropColumn(['content', 'is_visible']);
        });
    }
};
