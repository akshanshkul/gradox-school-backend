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
            $table->string('slug')->unique()->nullable();
            $table->string('custom_domain')->unique()->nullable();
            $table->string('logo_path')->nullable();
            $table->string('theme_color')->nullable();
            $table->string('tagline')->nullable();
            $table->text('about_text')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['slug', 'custom_domain', 'logo_path', 'theme_color', 'tagline', 'about_text']);
        });
    }
};
