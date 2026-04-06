<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

use App\Traits\ClearsSchoolCache;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, ClearsSchoolCache;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'school_id',
        'role',
        'is_teaching',
        'staff_subtype',
        'profile_picture',
        'teacher_details',
        'status',
        'exit_date'
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function timetableEntries()
    {
        return $this->hasMany(TimetableEntry::class);
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_teaching' => 'boolean',
        'teacher_details' => 'array',
        'status' => 'string',
        'exit_date' => 'date',
    ];
}
