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
        Schema::table('student_logins', function (Blueprint $table) {
            $table->timestamp('last_read_circular_at')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('student_logins', function (Blueprint $table) {
            $table->dropColumn('last_read_circular_at');
        });
    }
};
