<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LandingSection extends Model
{
    use HasFactory;
    protected $fillable = ['school_id', 'title', 'type', 'is_active', 'sort_order'];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function cards()
    {
        return $this->hasMany(LandingSectionCard::class)->orderBy('sort_order');
    }
}
