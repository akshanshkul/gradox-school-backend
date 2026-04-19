<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeeAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'fee_type_id',
        'session_id',
        'grade_id',
        'class_id',
        'student_id',
        'amount',
        'due_day',
        'due_date',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'due_date' => 'date',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function feeType()
    {
        return $this->belongsTo(FeeType::class);
    }

    public function session()
    {
        return $this->belongsTo(Session::class);
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function installments()
    {
        return $this->hasMany(FeeInstallment::class);
    }

    public function discounts()
    {
        return $this->hasMany(FeeDiscount::class);
    }

    public function payments()
    {
        return $this->hasMany(FeePayment::class);
    }
}
