<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentDeviceToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'token',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
