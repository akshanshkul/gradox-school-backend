<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentType extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'name',
        'slug',
        'is_required',
        'description'
    ];

    protected $casts = [
        'is_required' => 'boolean'
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
