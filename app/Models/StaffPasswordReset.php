<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pending password-reset state for a staff/teacher user. Created when
 * they submit the "Forgot password" form, then mutated through two
 * stages: OTP issued → OTP verified (token issued) → token consumed.
 *
 * Both `otp` and `token` are stored as bcrypt hashes — the plaintext
 * `otp` is only ever in the email; the plaintext `token` is only ever
 * in the verify-OTP JSON response. We compare via Hash::check.
 */
class StaffPasswordReset extends Model
{
    protected $fillable = [
        'school_id',
        'email',
        'otp',
        'token',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
}
