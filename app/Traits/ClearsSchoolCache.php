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
        }
    }
}
