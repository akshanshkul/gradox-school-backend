<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClassSubjectSyllabus extends Model
{
    use HasFactory;

    protected $table = 'class_subject_syllabus';

    protected $fillable = [
        'class_subject_id',
        'topic',
        'description',
        'status'
    ];

    public function classSubject()
    {
        return $this->belongsTo(ClassSubject::class, 'class_subject_id');
    }
}
