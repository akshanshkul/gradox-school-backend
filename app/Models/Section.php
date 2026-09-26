<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use App\Traits\ClearsSchoolCache;

class Section extends Model
{
    use HasFactory, ClearsSchoolCache;

    protected static function booted()
    {
        static::saved(function ($section) {
            $school = $section->school;
            if ($school && !$school->isOnboardingStepCompleted('grades-sections')) {
                $hasGrades = \App\Models\Grade::where('school_id', $school->id)->exists();
                if ($hasGrades) {
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
}
