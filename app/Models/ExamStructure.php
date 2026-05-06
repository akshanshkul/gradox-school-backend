<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamStructure extends Model
{
    use HasFactory;

    protected $fillable = ['exam_term_id', 'exam_type_id', 'school_class_id', 'subject_id', 'scoring_type', 'passing_marks', 'is_published'];

    public function term()
    {
        return $this->belongsTo(ExamTerm::class, 'exam_term_id');
    }

    public function type()
    {
        return $this->belongsTo(ExamType::class, 'exam_type_id');
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function components()
    {
        return $this->hasMany(ExamStructureComponent::class);
    }

    public function studentMarks()
    {
        return $this->hasMany(StudentExamMark::class);
    }
}
