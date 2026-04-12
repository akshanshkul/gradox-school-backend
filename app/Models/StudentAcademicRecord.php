<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentAcademicRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'school_class_id',
        'section_id',
        'academic_year',
        'roll_number',
        'status',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function section()
    {
        return $this->belongsTo(Section::class);
    }
}
