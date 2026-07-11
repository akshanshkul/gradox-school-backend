<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class PlatformAdmin extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'platform_admins';

    /**
     * NOTE: `role` and `status` are deliberately NOT in $fillable. They are
     * privileged fields — only the TeamController (owner-only routes) should
     * be allowed to write them. Mass-assignment via `update($request->all())`
     * in any future self-service code path (profile, password reset, etc.)
     * would otherwise let a staff admin promote themselves to owner or
     * reactivate a suspended account.
     *
     * Code that legitimately needs to set them uses `forceFill()` after an
     * explicit authorization check — see TeamController::update / ::store.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'password' => 'hashed',
        'last_login_at' => 'datetime',
    ];

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
