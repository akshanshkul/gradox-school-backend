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
        'latitude',
        'longitude',
        'photo_path',
        'check_in_time',
        'check_out_time',
        'device_metadata',
    ];

    protected $appends = ['photo_url'];

    public function getPhotoUrlAttribute()
    {
        if (!$this->photo_path) return null;
        if (str_starts_with($this->photo_path, 'http')) return $this->photo_path;
        return \Illuminate\Support\Facades\Storage::url($this->photo_path);
    }

    protected $casts = [
        'is_regularized' => 'boolean',
        'date' => 'date:Y-m-d',
        'latitude' => 'float',
        'longitude' => 'float',
        'device_metadata' => 'array',
    ];

    public function staffMember()
    {
        return $this->belongsTo(User::class, 'user_id');
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
