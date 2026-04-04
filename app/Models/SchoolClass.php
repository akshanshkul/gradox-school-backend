<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Grade;
use App\Models\Section;

class SchoolClass extends Model
{
    use HasFactory;

    protected $fillable = ['school_id', 'grade_id', 'section_id', 'class_teacher_id', 'default_classroom_id'];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function classTeacher()
    {
        return $this->belongsTo(User::class, 'class_teacher_id');
    }

    public function defaultClassroom()
    {
        return $this->belongsTo(Classroom::class, 'default_classroom_id');
    }

    public function timetableEntries()
    {
        return $this->hasMany(TimetableEntry::class);
    }

    public function subjects()
    {
        return $this->belongsToMany(Subject::class, 'class_subject')->withPivot('periods_per_week');
    }
}
