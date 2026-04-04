<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'slug',
        'name',
        'subject',
        'content_html',
        'placeholders',
        'is_system'
    ];

    protected $casts = [
        'placeholders' => 'array',
        'is_system' => 'boolean'
    ];

    /**
     * Render the template with dynamic data.
     * Replaces {{tag}} with values from the provided array.
     */
    public function render(array $data)
    {
        $placeholders = [];
        foreach ($data as $key => $value) {
            $placeholders["{{{$key}}}"] = $value;
        }

        return [
            'subject' => strtr($this->subject, $placeholders),
            'content_html' => strtr($this->content_html, $placeholders)
        ];
    }

    /**
     * Scope to find template for a school or fallback to global.
     */
    public static function findBySlug($slug, $schoolId = null)
    {
        return self::where('slug', $slug)
            ->where(function ($q) use ($schoolId) {
                $q->where('school_id', $schoolId);
                if ($schoolId) {
                    $q->orWhereNull('school_id');
                }
            })
            ->orderBy('school_id', 'desc') // School specific first
            ->first();
    }
}
