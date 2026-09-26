<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use App\Traits\ClearsSchoolCache;

class Grade extends Model
{
    use HasFactory, ClearsSchoolCache;

    protected static function booted()
    {
        static::saved(function ($grade) {
            $school = $grade->school;
            if ($school && !$school->isOnboardingStepCompleted('grades-sections')) {
                $hasSections = \App\Models\Section::where('school_id', $school->id)->exists();
                if ($hasSections) {
                    $school->completeOnboardingStep('grades-sections');
                }
            }
        });
    }

    protected $fillable = ['name', 'school_id'];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function feeAssignments()
    {
        return $this->hasMany(FeeAssignment::class);
    }
}
