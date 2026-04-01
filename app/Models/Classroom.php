<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Classroom extends Model
{
    use HasFactory;

    protected $fillable = ['school_id', 'name', 'capacity', 'type'];

    public function timetableEntries()
    {
        return $this->hasMany(TimetableEntry::class);
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
