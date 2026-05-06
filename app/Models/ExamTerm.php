<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamTerm extends Model
{
    use HasFactory;

    protected $fillable = ['school_id', 'session_id', 'name', 'weightage', 'is_active'];

    public function session()
    {
        return $this->belongsTo(Session::class);
    }

    public function examStructures()
    {
        return $this->hasMany(ExamStructure::class);
    }
}
