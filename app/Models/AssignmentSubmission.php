<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One student's PDF submission for an assignment.
 *
 * See `2026_06_14_000002_create_assignment_submissions_table.php`
 * for the column-level rationale.
 *
 * Multi-tenant note: queries against this model MUST be scoped by
 * `school_id`. The FK to homework is insufficient because callers
 * occasionally use whereHas() patterns that skip the school filter.
 */
class AssignmentSubmission extends Model
{
    protected $fillable = [
        'school_id',
        'homework_id',
        'student_id',
        'file_path',
        'file_url',
        'file_name',
        'file_size',
        'mime_type',
        'submitted_at',
        'marks',
        'feedback',
        'graded_by',
        'graded_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'graded_at'    => 'datetime',
        'marks'        => 'decimal:2',
    ];

    public function homework()
    {
        return $this->belongsTo(Homework::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    /** The staff user who graded it (nullable until graded). */
    public function grader()
    {
        return $this->belongsTo(User::class, 'graded_by');
    }
}
