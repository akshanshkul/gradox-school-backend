<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix a latent schema bug in student_password_resets.
 *
 * The original migration declared `otp` as NOT NULL, but the controller
 * code in StudentController::verifyOtp clears `otp` to NULL after the
 * code is verified (so it can't be replayed). That UPDATE was crashing
 * with:
 *
 *   SQLSTATE[23000]: Integrity constraint violation: 1048
 *   Column 'otp' cannot be null
 *
 * …meaning the entire student forgot-password flow could never get past
 * the OTP entry step. Bug had been live since day one; it surfaced now
 * because nobody had actually completed the flow end-to-end before.
 *
 * Both `otp` and `token` are now nullable for the same reason: each one
 * is set during a specific stage (otp during request, token during
 * verify) and cleared at the boundary so the row can't double up as a
 * replayable artifact.
 *
 * This mirrors the staff_password_resets schema I just added — same
 * three-stage lifecycle, same constraints.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_password_resets', function (Blueprint $table) {
            // `change()` requires doctrine/dbal, which Laravel 10 still
            // bundles. Both columns made nullable to match the stage
            // semantics: otp nulls after verify, token nulls after reset.
            $table->string('otp')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Note: rolling back is technically destructive. If any row has
        // otp=NULL when this runs, MySQL refuses the change. The down()
        // wipes those rows first so the column can re-tighten to NOT NULL.
        Schema::table('student_password_resets', function (Blueprint $table) {
            \DB::table('student_password_resets')->whereNull('otp')->delete();
            $table->string('otp')->nullable(false)->change();
        });
    }
};
