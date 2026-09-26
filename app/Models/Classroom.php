<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use App\Traits\ClearsSchoolCache;

class Classroom extends Model
{
    use HasFactory, ClearsSchoolCache;

    protected static function booted()
    {
        static::saved(function ($classroom) {
            $school = $classroom->school;
            if ($school && !$school->isOnboardingStepCompleted('classrooms')) {
                $school->completeOnboardingStep('classrooms');
            }
        });
    }

    protected $fillable = ['school_id', 'name', 'capacity', 'type'];

    public function timetableEntries()
    {
        return $this->hasMany(TimetableEntry::class);
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
