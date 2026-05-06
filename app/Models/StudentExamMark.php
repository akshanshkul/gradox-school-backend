<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentExamMark extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id', 
        'exam_structure_id', 
        'component_marks', 
        'total_obtained', 
        'grade_obtained', 
        'attendance_status', 
        'teacher_remarks'
    ];

    protected $casts = [
        'component_marks' => 'array'
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function structure()
    {
        return $this->belongsTo(ExamStructure::class, 'exam_structure_id');
    }

    public function examStructure()
    {
        return $this->structure();
    }
}
