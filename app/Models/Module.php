<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One row per feature in the platform's module catalog.
 *
 * See create_modules_table migration for column-level rationale. This
 * model owns three relationships that matter for the access-resolver:
 *
 *   - `dependencies()` — modules this one needs. If `report_cards`
 *     depends on `exams`, you can't enable report_cards alone.
 *   - `dependents()`   — modules that need this one. If you try to
 *     disable `exams` and report_cards depends on it, the
 *     dependents() collection is non-empty and the UI refuses.
 *   - `plans()` — which plans include this module by default.
 *
 * `is_core` modules are exempt from gating. EnsureModuleAvailable
 * middleware checks this and short-circuits, so login/dashboard/
 * profile can never accidentally be blocked.
 */
class Module extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code', 'name', 'description', 'category', 'icon',
        'surfaces', 'is_addon', 'is_core', 'sort_order',
    ];

    protected $casts = [
        'surfaces'   => 'array',
        'is_addon'   => 'boolean',
        'is_core'    => 'boolean',
        'sort_order' => 'integer',
    ];

    /** Modules this one depends on (must be enabled together). */
    public function dependencies()
    {
        return $this->belongsToMany(
            Module::class,
            'module_dependencies',
            'module_id',
            'depends_on_module_id'
        );
    }

    /** Modules that depend on this one (disable cascade source). */
    public function dependents()
    {
        return $this->belongsToMany(
            Module::class,
            'module_dependencies',
            'depends_on_module_id',
            'module_id'
        );
    }

    public function plans()
    {
        return $this->belongsToMany(Plan::class, 'plan_modules')
            ->withPivot('is_included')
            ->withTimestamps();
    }

    public function overrides()
    {
        return $this->hasMany(SchoolModuleOverride::class);
    }
}
