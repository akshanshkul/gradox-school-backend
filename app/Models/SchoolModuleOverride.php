<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-school override on top of plan-default module access.
 *
 * See create_school_module_overrides_table for column rationale.
 *
 * Saving / deleting an override row MUST trigger
 * ModuleAccessService::recompute() for the affected school so the
 * cached bitmap stays in sync. The boot hook below does this
 * automatically — code that touches overrides directly via DB::table()
 * (e.g. seeders, raw queries) must call recompute() manually.
 */
class SchoolModuleOverride extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id', 'module_id', 'state', 'reason', 'expires_at', 'granted_by',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function module()
    {
        return $this->belongsTo(Module::class);
    }

    public function grantedBy()
    {
        return $this->belongsTo(\App\Models\PlatformAdmin::class, 'granted_by');
    }

    /** True if the override is currently in effect (not past expiry). */
    public function isActive(): bool
    {
        if ($this->state !== 'enabled' && $this->state !== 'disabled') return false;
        if ($this->expires_at && $this->expires_at->isPast()) return false;
        return true;
    }

    /**
     * Eloquent boot — propagate any save/delete back to the
     * school's cached bitmap. Deferred via a deferred dispatch on
     * `saved`/`deleted` (rather than the `saving`/`deleting` hooks)
     * so the override row is already committed before the recompute
     * reads it back.
     */
    protected static function booted()
    {
        $invalidate = function (SchoolModuleOverride $override) {
            $school = $override->school;
            if ($school) {
                \App\Services\ModuleAccessService::recompute($school);
            }
        };
        static::saved($invalidate);
        static::deleted($invalidate);
    }
}
