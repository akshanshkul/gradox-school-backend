<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentPasswordReset extends Model
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
