<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-school password reset workflow for staff (teachers, admins,
 * incharges — anything in the `users` table).
 *
 * Mirrors `student_password_resets`:
 *   - Two-stage flow: OTP → reset_token → password change
 *   - school_id is part of the lookup key so the same teacher email
 *     can independently reset across multiple tenants (rare but legal)
 *   - `otp` and `token` are both hashed at rest; only the plaintext
 *     copy ever leaves the server (email and JSON response respectively)
 *   - 10-minute OTP window, 15-minute reset-token window after verify
 *
 * Kept separate from `student_password_resets` because students and
 * staff live in different tables (`student_logins` vs `users`) and have
 * different mail templates / branding ("Hello [Student Name]" vs
 * "Hello [Teacher Name] from [School]").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_password_resets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->string('email');
            $table->string('otp')->nullable();
            $table->string('token')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            // One pending reset per (school, email) — updateOrCreate
            // collapses retries onto the same row instead of stacking
            // dozens of dead rows when a user keeps clicking "send OTP".
            $table->unique(['school_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_password_resets');
    }
};
