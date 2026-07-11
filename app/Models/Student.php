<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'admission_number',
        'aadhaar_number',
        'name',
        'email',
        'phone',
        'gender',
        'date_of_birth',
        'admission_date',
        'parent_name',
        'parent_phone',
        'parent_occupation',
        'address',
        'previous_school',
        'tc_details',
        'photo_path',
        'parent_email',
        'status'
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'admission_date' => 'date',
    ];

    /**
     * Enforce the plan's student cap at the model layer so EVERY entry point
     * (admission controller, bulk import, manual student-create endpoint,
     * etc.) gets the same gate. Bypass with `Student::withoutLimitCheck()->create(...)`
     * for migrations / seeders that need to skip enforcement.
     */
    protected static bool $skipLimitCheck = false;

    public static function withoutLimitCheck(): static
    {
        static::$skipLimitCheck = true;
        return new static;
    }

    protected static function booted(): void
    {
        static::creating(function (Student $student) {
            if (static::$skipLimitCheck) {
                static::$skipLimitCheck = false; // single-use
                return;
            }
            if (($student->status ?? 'active') !== 'active') {
                return; // archived/inactive imports don't count against the cap
            }

            // Only ENFORCE the limit when an admin is the actor.
            //
            // Rationale: teachers and other staff shouldn't be blocked from
            // their day-to-day work by a plan-billing constraint they can't
            // resolve. If a teacher's bulk import or manual entry tips the
            // school over the plan cap, the admin sees it on their usage
            // banner the next time they log in and decides whether to
            // upgrade, request a bump, or remove records.
            //
            // When there's no auth context at all (CLI seeder, queued job,
            // webhook) we default to ENFORCE so an unattended path can't
            // silently exceed billing limits.
            $caller = auth()->user();
            if ($caller) {
                $roleSlug = $caller->role_relation?->slug ?? null;
                $isAdmin = in_array(
                    $roleSlug,
                    ['administrator', 'admin', 'super-admin', 'incharge'],
                    true
                );
                if (!$isAdmin) {
                    return; // non-admin action — let it proceed silently
                }
            }

            $school = School::with('plan')->find($student->school_id);
            if (!$school) return; // edge case — school FK is required upstream

            $svc = app(\App\Services\StudentLimitService::class);
            if (!$svc->canAddStudents($school)) {
                throw new \App\Services\OverLimitException(
                    'Student cap reached for the ' . ($school->plan?->name ?? 'current') . ' plan. '
                    . 'Current usage: ' . $school->currentStudentCount() . ' / ' . $school->effectiveStudentLimit() . '. '
                    . 'Upgrade the plan or request an additional-student bump from the platform admin.'
                );
            }
        });
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function academicRecords()
    {
        return $this->hasMany(StudentAcademicRecord::class);
    }

    /**
     * Get the student's academic record for a specific academic year.
     * Use with constraints (e.g., ->where('academic_year', '2024-25'))
     */
    public function currentRecord()
    {
        return $this->hasOne(StudentAcademicRecord::class);
    }

    public function login()
    {
        return $this->hasOne(StudentLogin::class);
    }

    public function documents()
    {
        return $this->hasMany(StudentDocument::class);
    }

    public function feeAssignments()
    {
        return $this->hasMany(FeeAssignment::class);
    }

    public function fines()
    {
        return $this->hasMany(StudentFine::class);
    }

    public function payments()
    {
        return $this->hasMany(FeePayment::class);
    }

    public function examMarks()
    {
        return $this->hasMany(StudentExamMark::class);
    }

    public function scholasticAssessments()
    {
        return $this->hasMany(ScholasticAssessment::class);
    }
}
