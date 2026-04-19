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
        'role_id',
        'is_teaching',
        'staff_subtype',
        'phone',
        'profile_picture',
        'teacher_details',
        'bio',
        'status',
        'exit_date',
        'permission_overrides'
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
        'permission_overrides' => 'array',
        'status' => 'string',
        'exit_date' => 'date',
    ];

    protected $appends = ['photo_path'];

    public function getPhotoPathAttribute()
    {
        return $this->profile_picture;
    }

    public const SLUG_SUPER_ADMIN = 'super-admin';
    public const SLUG_ADMIN = 'administrator';
    public const SLUG_TEACHER = 'teacher';

    public function role_relation()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function isSuperAdmin()
    {
        return $this->role_id && $this->role_relation && $this->role_relation->slug === self::SLUG_SUPER_ADMIN;
    }

    public function isAdmin()
    {
        if ($this->role_id && $this->role_relation) {
            return in_array($this->role_relation->slug, [self::SLUG_SUPER_ADMIN, self::SLUG_ADMIN, 'admin']);
        }
        return false;
    }

    /**
     * Check granular permission
     * @param string $resource (e.g. 'blogs', 'users', 'students')
     * @param string $action (e.g. 'read', 'create', 'delete', 'export')
     */
    public function canAccess($resource, $action)
    {
        if ($this->isSuperAdmin()) return true;

        // 1. Check direct overrides (highest priority)
        if (isset($this->permission_overrides[$resource][$action])) {
            return (bool)$this->permission_overrides[$resource][$action];
        }

        // 2. Check role-based permissions
        if ($this->role_id && $this->role_relation) {
            $rolePerms = $this->role_relation->permissions;
            if (isset($rolePerms[$resource][$action])) {
                return (bool)$rolePerms[$resource][$action];
            }
        }

        return false;
    }

    public function hasPermission($permission)
    {
        if ($this->isSuperAdmin()) return true;
        return $this->canAccess(explode('.', $permission)[0], explode('.', $permission)[1] ?? 'read');
    }
    public function managedClasses()
    {
        return $this->hasMany(SchoolClass::class, 'class_teacher_id');
    }
}
