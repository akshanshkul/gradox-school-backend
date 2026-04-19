<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeeDiscount extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'fee_assignment_id',
        'discount_type',
        'value',
        'is_applied',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'is_applied' => 'boolean',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function assignment()
    {
        return $this->belongsTo(FeeAssignment::class, 'fee_assignment_id');
    }
}
