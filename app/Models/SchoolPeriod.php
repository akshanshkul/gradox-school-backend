<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use App\Traits\ClearsSchoolCache;

class SchoolPeriod extends Model
{
    use ClearsSchoolCache;
    protected $fillable = [
        'school_id',
        'name',
        'start_time',
        'end_time',
        'type',
        'sort_order',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
