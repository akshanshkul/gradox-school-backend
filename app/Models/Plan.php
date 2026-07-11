<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'price',
        'currency',
        'billing_cycle',
        'pricing_model',      // 'fixed' | 'per_student'
        'price_per_student',  // used when pricing_model = 'per_student'
        'max_students',
        'max_users',
        'features',
        'is_active',
        'sort_order',
        'description',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'price_per_student' => 'decimal:2',
        'features' => 'array',
        'is_active' => 'boolean',
        'max_students' => 'integer',
        'max_users' => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * Effective monthly bill for a school on this plan.
     *
     *   - 'fixed'       → $this->price (a flat number)
     *   - 'per_student' → $this->price_per_student × current student count
     *                     (caller passes count, since the Plan model doesn't
     *                     know which school is asking)
     */
    public function effectivePriceFor(int $studentCount): float
    {
        if ($this->pricing_model === 'per_student') {
            return round((float) $this->price_per_student * max(0, $studentCount), 2);
        }
        return (float) $this->price;
    }

    public function schools()
    {
        return $this->hasMany(School::class, 'plan_id');
    }

    /**
     * Modules included in this plan. Used by ModuleAccessService when
     * resolving a school's effective module set. Filter at usage sites
     * with `->wherePivot('is_included', true)` — `is_included=false`
     * rows are explicit excludes from a future macro / tier inheritance.
     */
    public function modules()
    {
        return $this->belongsToMany(Module::class, 'plan_modules')
            ->withPivot('is_included')
            ->withTimestamps();
    }

    /**
     * When the plan name is edited from the SaaS-admin Plans page, propagate
     * the new name to every school's cached `schools.plan_name` column.
     *
     * Schools relate to plans by the `plan_id` FK, so usage / limit / pricing
     * code is unaffected by a rename — it reads `$school->plan->name` live.
     * But the denormalized `schools.plan_name` string is still surfaced in a
     * few admin lists and used by PlanController::destroy's usage check.
     * Without this hook a rename like "Basic" → "Starter" would leave every
     * existing school showing the old name until manually touched.
     */
    protected static function booted()
    {
        static::updated(function (Plan $plan) {
            if ($plan->wasChanged('name')) {
                School::where('plan_id', $plan->id)->update(['plan_name' => $plan->name]);
            }
        });
    }
}
