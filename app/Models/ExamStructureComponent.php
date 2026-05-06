<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamStructureComponent extends Model
{
    use HasFactory;

    protected $fillable = ['exam_structure_id', 'name', 'max_marks'];

    public function structure()
    {
        return $this->belongsTo(ExamStructure::class, 'exam_structure_id');
    }
}
