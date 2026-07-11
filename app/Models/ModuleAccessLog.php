<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit row for a module-access decision.
 *
 * See create_module_access_logs_table for rationale on what we
 * log (denied events) and how it's used (support, product, sales,
 * engineering).
 *
 * `created_at` is the only timestamp; we don't track updated_at
 * because rows are immutable — write-once, prune after 30 days.
 */
class ModuleAccessLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'school_id', 'module_id', 'user_id', 'route',
        'outcome', 'denied_reason', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function module()
    {
        return $this->belongsTo(Module::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
