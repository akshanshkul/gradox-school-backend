<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LandingSectionCard extends Model
{
    use HasFactory;
    protected $fillable = ['landing_section_id', 'image_path', 'title', 'description', 'sort_order'];

    public function section()
    {
        return $this->belongsTo(LandingSection::class, 'landing_section_id');
    }
}
