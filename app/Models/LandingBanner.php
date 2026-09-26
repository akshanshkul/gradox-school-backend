<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\ClearsPublicSiteCache;

class LandingBanner extends Model
{
    use HasFactory, ClearsPublicSiteCache;
    protected $fillable = ['school_id', 'image_path', 'title', 'subtitle', 'sort_order'];

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
