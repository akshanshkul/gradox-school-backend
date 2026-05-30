<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClassSubjectNote extends Model
{
    use HasFactory;

    protected $table = 'class_subject_notes';

    protected $fillable = [
        'class_subject_id',
        'title',
        'file_url',
        'description'
    ];

    public function classSubject()
    {
        return $this->belongsTo(ClassSubject::class, 'class_subject_id');
    }
}
