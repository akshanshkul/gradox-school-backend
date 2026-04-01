<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoleWorkloadConfig extends Model
{
    protected $fillable = [
        'school_id',
        'role_name',
        'min_classes_per_day',
        'max_classes_per_day',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
