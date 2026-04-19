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
        'status'
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'admission_date' => 'date',
    ];

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
}
