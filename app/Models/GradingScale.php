<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GradingScale extends Model
{
    use HasFactory;

    protected $fillable = ['school_id', 'min_percent', 'max_percent', 'grade', 'grade_point', 'description'];

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
