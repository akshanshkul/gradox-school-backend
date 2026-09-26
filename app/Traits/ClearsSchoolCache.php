<?php

namespace App\Traits;

use App\Services\SafeCache;

trait ClearsSchoolCache
{
    public static function bootClearsSchoolCache()
    {
        static::saved(function ($model) {
            static::clearSchoolCache($model);
        });

        static::deleted(function ($model) {
            static::clearSchoolCache($model);
        });
    }

    protected static function clearSchoolCache($model)
    {
        $schoolId = $model->school_id ?? null;
        
        // If it's the School model itself, the ID is the school ID
        if (!$schoolId && $model instanceof \App\Models\School) {
            $schoolId = $model->id;
        }

        if (!$schoolId && method_exists($model, 'school')) {
            $schoolId = $model->school()->first()?->id;
        }

        if ($schoolId) {
            SafeCache::forget("school_{$schoolId}_timetable_scheduling_data");
            SafeCache::forget("school_{$schoolId}_dashboard_general_data");
            // Drop every per-user GET response cached for this school
            // (bootstrap, school config, notification counts, etc.).
            SafeCache::forgetPrefix("school_{$schoolId}_url_cache");

            // Public landing payload (Valkey) includes school identity and the
            // class list — only those models need to flush it.
            if ($model instanceof \App\Models\School) {
                \App\Services\PublicSiteCache::forgetSchool($model);
            } elseif ($model instanceof \App\Models\SchoolClass
                || $model instanceof \App\Models\Grade
                || $model instanceof \App\Models\Section) {
                \App\Services\PublicSiteCache::forgetSchoolId($schoolId);
            }
        }
    }
}
