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
        Schema::table('inquiries', function (Blueprint $table) {
            $table->index(['school_id', 'status']);
        });

        Schema::table('admission_applications', function (Blueprint $table) {
            $table->index(['school_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropIndex(['school_id', 'status']);
        });

        Schema::table('admission_applications', function (Blueprint $table) {
            $table->dropIndex(['school_id', 'status']);
        });
    }
};
