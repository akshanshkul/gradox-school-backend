<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use App\Traits\ClearsSchoolCache;

class Subject extends Model
{
    use HasFactory, ClearsSchoolCache;

    protected $fillable = ['school_id', 'name', 'code'];

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
