<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'fee_payment_id',
        'amount',
        'payment_date',
        'method',
        'added_by',
        'remarks',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date',
    ];

    public function receipt()
    {
        return $this->belongsTo(FeePayment::class, 'fee_payment_id');
    }

    public function addedBy()
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function razorpayDetail()
    {
        return $this->hasOne(RazorpayTransaction::class);
    }
}
