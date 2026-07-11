<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Homework extends Model
{
    protected $fillable = [
        'school_id',
        'created_by',
        'school_class_id',
        'subject_id',
        'kind',         // 'homework' (text, for_date) | 'assignment' (due_date + PDF submissions)
        'title',
        'description',
        'for_date',     // calendar day the homework is FOR (homework kind)
        'due_date',     // submission deadline (assignment kind)
        'status',
    ];

    protected $casts = [
        'for_date' => 'date',
        'due_date' => 'date',
    ];

    /** Convenience flags so callers don't have to compare strings. */
    public function isAssignment(): bool { return $this->kind === 'assignment'; }
    public function isHomework(): bool   { return $this->kind === 'homework' || $this->kind === null; }

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'school_class_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * All submissions for this row. Only meaningful for `assignment`
     * rows — homework rows will always have zero.
     */
    public function submissions()
    {
        return $this->hasMany(AssignmentSubmission::class);
    }
}