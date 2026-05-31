<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds transport + hostel logistics to the students table.
 *
 * - transport_required (bool): does the school pick this student up
 * - transport_route   (str) : route name / number — free text for now,
 *                             can be promoted to a dedicated transport_routes
 *                             master table later without breaking anything.
 * - hostel_required   (bool): is the student a boarder
 *
 * All three are nullable so existing students are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            if (!Schema::hasColumn('students', 'transport_required')) {
                $table->boolean('transport_required')->default(false)->after('status');
            }
            if (!Schema::hasColumn('students', 'transport_route')) {
                $table->string('transport_route')->nullable()->after('transport_required');
            }
            if (!Schema::hasColumn('students', 'hostel_required')) {
                $table->boolean('hostel_required')->default(false)->after('transport_route');
            }
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            foreach (['transport_required', 'transport_route', 'hostel_required'] as $col) {
                if (Schema::hasColumn('students', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
