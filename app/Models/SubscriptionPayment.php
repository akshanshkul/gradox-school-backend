<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'order_id',
        'client_id',
        'transaction_id',
        'amount',
        'status',
        'payment_metadata'
    ];

    protected $casts = [
        'payment_metadata' => 'array',
        'amount' => 'decimal:2'
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
