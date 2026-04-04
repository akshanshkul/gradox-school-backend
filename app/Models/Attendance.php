<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'school_id',
        'date',
        'status',
        'remarks',
        'is_regularized',
        'regularize_remark',
        'regularized_by',
    ];

    protected $casts = [
        'is_regularized' => 'boolean',
        'date' => 'date:Y-m-d',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function regularizedBy()
    {
        return $this->belongsTo(User::class, 'regularized_by');
    }
}
