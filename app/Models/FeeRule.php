<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeeRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'fee_type_id',
        'rule_type',
        'amount',
        'amount_type',
        'condition_json',
    ];

    protected $casts = [
        'condition_json' => 'array',
        'amount' => 'decimal:2',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function feeType()
    {
        return $this->belongsTo(FeeType::class);
    }
}
