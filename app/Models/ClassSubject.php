<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ClassSubject extends Pivot
{
    protected $table = 'class_subject';

    public $incrementing = true;

    protected $fillable = [
        'school_class_id',
        'subject_id',
        'periods_per_week',
        'teacher_id'
    ];

    public function notes()
    {
        return $this->hasMany(ClassSubjectNote::class, 'class_subject_id');
    }

    public function syllabus()
    {
        return $this->hasMany(ClassSubjectSyllabus::class, 'class_subject_id');
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'school_class_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
}
