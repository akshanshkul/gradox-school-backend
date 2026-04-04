<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PeriodSubstitution extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'timetable_entry_id',
        'substitute_teacher_id',
        'substitute_subject_id',
        'date',
        'reason',
        'remarks',
        'is_active',
    ];

    protected $casts = [
        'date'      => 'date',
        'is_active' => 'boolean',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function timetableEntry()
    {
        return $this->belongsTo(TimetableEntry::class);
    }

    public function substituteTeacher()
    {
        return $this->belongsTo(User::class, 'substitute_teacher_id');
    }

    public function substituteSubject()
    {
        return $this->belongsTo(Subject::class, 'substitute_subject_id');
    }

    /**
     * Helper to get the original teacher being replaced.
     */
    public function originalTeacher()
    {
        return $this->hasOneThrough(
            User::class,
            TimetableEntry::class,
            'id', // Local key on timetable_entries
            'id', // Local key on users
            'timetable_entry_id', // Foreign key on period_substitutions
            'user_id' // Foreign key on timetable_entries
        );
    }
}
