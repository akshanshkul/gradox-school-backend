<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use App\Traits\ClearsSchoolCache;

class TimetableEntry extends Model
{
    use HasFactory, ClearsSchoolCache;

    protected $fillable = [
        'school_id',
        'school_class_id',
        'subject_id',
        'user_id',
        'classroom_id',
        'date',
        'day_of_week',
        'start_time',
        'end_time',
        'is_active',
    ];

    protected $casts = [
        'school_id' => 'integer',
        'school_class_id' => 'integer',
        'subject_id' => 'integer',
        'user_id' => 'integer',
        'classroom_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function classroom()
    {
        return $this->belongsTo(Classroom::class);
    }
}
