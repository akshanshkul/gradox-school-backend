<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlatformBroadcast extends Model
{
    use HasFactory;

    protected $fillable = [
        'sent_by_admin_id',
        'subject',
        'body',
        'type',
        'audience',
        'channel',
        'sent_to_count',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'sent_to_count' => 'integer',
    ];

    public function admin()
    {
        return $this->belongsTo(PlatformAdmin::class, 'sent_by_admin_id');
    }
}
