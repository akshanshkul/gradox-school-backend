<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdmissionApplication extends Model
{
    use HasFactory;
    protected $fillable = ['school_id', 'school_class_id', 'student_name', 'photo_path', 'parent_name', 'email', 'phone', 'metadata', 'status', 'admission_number'];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class);
    }
}
