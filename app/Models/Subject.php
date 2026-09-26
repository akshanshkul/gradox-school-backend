<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use App\Traits\ClearsSchoolCache;

class Subject extends Model
{
    use HasFactory, ClearsSchoolCache;

    protected static function booted()
    {
        static::saved(function ($subject) {
            $school = $subject->school;
            if ($school && !$school->isOnboardingStepCompleted('subjects')) {
                $school->completeOnboardingStep('subjects');
            }
        });
    }

    protected $fillable = ['school_id', 'name', 'code'];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function examStructures()
    {
        return $this->hasMany(ExamStructure::class);
    }
}
