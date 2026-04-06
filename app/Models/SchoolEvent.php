<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use App\Traits\ClearsSchoolCache;

class SchoolEvent extends Model
{
    use ClearsSchoolCache;
    protected $fillable = [
        'school_id',
        'name',
        'type',
        'duration',
        'target_type',
        'school_class_id',
        'date',
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
